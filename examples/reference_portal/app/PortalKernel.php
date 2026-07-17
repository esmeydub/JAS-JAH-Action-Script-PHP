<?php

declare(strict_types=1);

namespace JAS\ReferencePortal;

use Jah\DataCore\DataCoreBackupService;
use Jah\DataCore\DataCoreContinuityLock;
use Jah\DataCore\DataCoreDatabase;
use Jah\DataCore\DataCoreTurbo;
use Jah\JAS\Definition\JasApplication;
use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Queue\PersistentJobQueue;
use Jah\JAS\Runtime\GovernedRuntime;
use Jah\JAS\Security\DualControlStore;
use Jah\JAS\Security\InstitutionalIdentityService;
use Jah\JAS\Security\KeyRing;
use Jah\JAS\Type\TypeRegistry;
use RuntimeException;

foreach (glob(__DIR__ . '/Services/*.php') ?: [] as $service) require_once $service;

final class PortalKernel
{
    private DataCoreTurbo $storage;
    private DataCoreDatabase $database;
    private DataCoreContinuityLock $continuity;
    private AuditJournal $audit;
    private InstitutionalIdentityService $identity;
    private JasApplication $application;
    private PersistentJobQueue $feedQueue;
    private PersistentJobQueue $moderationQueue;
    private PersistentJobQueue $notificationQueue;
    private UserService $users;
    private PublicationService $publications;
    private FeedService $feeds;
    private MessageService $messages;
    private ModerationService $moderation;
    private NotificationService $notifications;
    private AuditService $audits;

    public function __construct(private readonly string $runtimeDirectory, string $masterKey, string $pepper)
    {
        if (strlen($masterKey) < 32 || strlen($pepper) < 32) throw new RuntimeException('portal_secrets_invalid');
        $this->continuity = new DataCoreContinuityLock($runtimeDirectory . '/datacore-continuity.lock');
        $this->storage = (new DataCoreTurbo($runtimeDirectory . '/datacore', 1))->continuityLock($this->continuity);
        $types = new TypeRegistry();
        InstitutionalIdentityService::defineTypes($types);
        $types->define('PortalPost', [
            'id' => 'identifier', 'author_id' => 'identifier', 'content' => 'non-empty-string',
            'status' => 'non-empty-string', 'created_at' => 'positive-int',
        ]);
        $types->define('PortalMessage', [
            'id' => 'identifier', 'sender_id' => 'identifier', 'recipient_id' => 'identifier',
            'body' => 'non-empty-string', 'created_at' => 'positive-int',
        ]);
        $types->define('PortalNotification', [
            'id' => 'identifier', 'user_lookup' => 'non-empty-string', 'user_id' => 'identifier',
            'kind' => 'non-empty-string', 'subject_id' => 'identifier', 'read' => 'bool',
            'created_at' => 'positive-int',
        ]);
        // Índices y locks viven dentro del árbol respaldado de DataCore.
        $this->database = new DataCoreDatabase($this->storage, $types, $runtimeDirectory . '/datacore/runtime', $masterKey);
        InstitutionalIdentityService::configureDatabase($this->database);
        $this->database
            ->collection('portal_posts', 'PortalPost')
            ->index('portal_posts', 'posts_by_status', ['status'])
            ->reference('portal_posts', 'post_author', 'author_id', 'identity_users')
            ->encryptFields('portal_posts', ['author_id', 'content'])
            ->collection('portal_messages', 'PortalMessage')
            ->reference('portal_messages', 'message_sender', 'sender_id', 'identity_users')
            ->reference('portal_messages', 'message_recipient', 'recipient_id', 'identity_users')
            ->encryptFields('portal_messages', ['sender_id', 'recipient_id', 'body'])
            ->collection('portal_notifications', 'PortalNotification')
            ->index('portal_notifications', 'notifications_by_user', ['user_lookup'])
            ->reference('portal_notifications', 'notification_user', 'user_id', 'identity_users')
            ->encryptFields('portal_notifications', ['user_id']);

        $this->audit = new AuditJournal($runtimeDirectory . '/audit');
        $this->identity = new InstitutionalIdentityService(
            $this->database, $this->audit,
            new DualControlStore($runtimeDirectory . '/identity-approvals'), $pepper,
        );
        $this->feedQueue = new PersistentJobQueue($runtimeDirectory . '/queues/feed');
        $this->moderationQueue = new PersistentJobQueue($runtimeDirectory . '/queues/moderation');
        $this->notificationQueue = new PersistentJobQueue($runtimeDirectory . '/queues/notification');
        $this->notifications = new NotificationService($this->database, $this->notificationQueue);
        $this->publications = new PublicationService($this->database, $this->feedQueue, $this->moderationQueue);
        $this->users = new UserService($this->identity);
        $this->feeds = new FeedService($this->publications);
        $this->messages = new MessageService($this->database, $this->notifications);
        $this->moderation = new ModerationService($this->publications, $this->notifications);
        $this->audits = new AuditService($this->audit);
        $application = require __DIR__ . '/application.php';
        if (!$application instanceof JasApplication) throw new RuntimeException('portal_application_invalid');
        $this->application = $application;
    }

    public function bootstrap(string $adminPassword): void
    {
        if ($this->database->find('identity_users', 'USER-ADMIN') !== null) return;
        $roles = [
            'citizen' => ['publications.create', 'feeds.read', 'messages.send', 'notifications.read'],
            'moderator' => ['moderation.review', 'feeds.read', 'notifications.read'],
            'auditor' => ['audit.verify'],
            'admin' => ['users.create', 'audit.verify'],
        ];
        foreach ($roles as $role => $permissions) $this->identity->defineRole('SYSTEM-BOOTSTRAP', $role, $permissions);
        $this->identity->createUser('SYSTEM-BOOTSTRAP', 'USER-ADMIN', 'admin', 'Administrador inicial', $adminPassword);
        $this->identity->assignRole('SYSTEM-BOOTSTRAP', 'USER-ADMIN', 'admin');
    }

    public function anonymousRuntime(): GovernedRuntime
    {
        return $this->runtime('anonymous', ['identity.login']);
    }

    public function runtimeForToken(string $token): GovernedRuntime
    {
        $identity = $this->identity->identity($token) ?? throw new RuntimeException('portal_session_invalid');
        return $this->runtime((string) $identity['id'], (array) $identity['permissions']);
    }

    public function queueStats(): array
    {
        return [
            'feed' => $this->feedQueue->stats(),
            'moderation' => $this->moderationQueue->stats(),
            'notification' => $this->notificationQueue->stats(),
        ];
    }

    public function backupService(string $backupDirectory, KeyRing $keys): DataCoreBackupService
    {
        return new DataCoreBackupService(
            $this->runtimeDirectory . '/datacore', $backupDirectory, $keys, $this->continuity,
            67_108_864, [fn(): null => $this->flush()],
        );
    }

    public function describe(): array { return $this->application->describe(); }

    private function flush(): null { $this->storage->flush(); return null; }

    private function runtime(string $principal, array $permissions): GovernedRuntime
    {
        $runtime = $this->application->runtime(
            [$principal => array_values(array_unique($permissions))], $principal,
            $this->runtimeDirectory . '/actions/' . hash('sha256', $principal),
        );
        $runtime->handle('identidad.authenticate', function (array $input): array {
            $login = $this->identity->login(
                (string) $input['username'], (string) $input['password'],
                (string) $input['device_id'], (string) $input['device_label'],
            );
            if (($login['status'] ?? '') !== 'authenticated') throw new RuntimeException('portal_mfa_completion_required');
            $token = (string) $login['token'];
            $identity = $this->identity->identity($token) ?? throw new RuntimeException('portal_session_invalid');
            return ['id' => $input['id'], 'status' => 'authenticated', 'token' => $token, 'user_id' => $identity['id']];
        });
        $runtime->handle('usuario.register', fn(array $input): array => $this->users->register($principal, $input));
        $runtime->handle('publicacion.publish', fn(array $input): array => $this->publications->publish($principal, $input));
        $runtime->handle('feed.read', fn(array $input): array => $this->feeds->read($input));
        $runtime->handle('mensaje.send', fn(array $input): array => $this->messages->send($principal, $input));
        $runtime->handle('moderacion.review', fn(array $input): array => $this->moderation->review($input));
        $runtime->handle('notificacion.list', fn(array $input): array => [
            'id' => $input['id'], 'notifications' => $this->notifications->list($principal, max(1, min(100, (int) $input['limit']))),
        ]);
        $runtime->handle('auditoria.verify', fn(array $input): array => $this->audits->verify($input));
        return $runtime;
    }
}
