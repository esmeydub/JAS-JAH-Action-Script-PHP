<?php

declare(strict_types=1);

namespace Jah\JAS\Protocol;

final class Opcodes
{
    public const PING = 1;
    public const ACTION_EXECUTE = 100;
    public const OBJECT_EVENT = 110;
    public const OBJECT_STATE_GET = 111;
    public const OBJECT_STATE_PATCH = 112;
    public const WORKER_REGISTER = 200;
    public const WORKER_HEARTBEAT = 201;
    public const JOB_SUBMIT = 300;
    public const JOB_STATUS = 301;
    public const JOB_CANCEL = 302;
    public const QUEUE_STATS = 303;
    public const CLUSTER_STATUS = 400;
    public const CLUSTER_HEARTBEAT = 401;
    public const REPLICATION_EXPORT = 410;
    public const REPLICATION_IMPORT = 411;
    public const TELEMETRY_METRICS = 500;
    public const TELEMETRY_TRACES = 501;
    public const LANGUAGE_INITIALIZE = 600;
    public const LANGUAGE_INITIALIZED = 601;
    public const LANGUAGE_DOCUMENT_OPEN = 610;
    public const LANGUAGE_DOCUMENT_CHANGE = 611;
    public const LANGUAGE_DOCUMENT_CLOSE = 612;
    public const LANGUAGE_HOVER = 620;
    public const LANGUAGE_DEFINITION = 621;
    public const LANGUAGE_REFERENCES = 622;
    public const LANGUAGE_PREPARE_RENAME = 623;
    public const LANGUAGE_RENAME = 624;
    public const LANGUAGE_DIAGNOSTICS = 630;
    public const LANGUAGE_SHUTDOWN = 640;
    public const LANGUAGE_EXIT = 641;
    public const LANGUAGE_RESPONSE = 690;
    public const LANGUAGE_ERROR = 691;
    public const RESULT = 900;
    public const ERROR = 901;
}
