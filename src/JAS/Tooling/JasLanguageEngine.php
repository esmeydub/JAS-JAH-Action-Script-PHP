<?php

declare(strict_types=1);

namespace Jah\JAS\Tooling;

/**
 * Native PHP language intelligence for JAS definitions.
 *
 * This is a CLI-oriented engine. It does not implement the standard Language
 * Server Protocol or its JSON-RPC transport.
 */
final class JasLanguageEngine
{
    private readonly JasLanguageIndex $implementation;

    public function __construct(?JasLanguageIndex $implementation = null, ?DocumentStore $documents = null)
    {
        if ($implementation !== null && $documents !== null) throw new \InvalidArgumentException('language_engine_dependencies_conflict');
        $this->implementation = $implementation ?? new JasLanguageIndex(documents: $documents);
    }

    /** @return array{ok:bool,files:int,diagnostics:list<array{code:string,file:string,line:int,message:string}>} */
    public function diagnostics(string $project): array
    {
        return $this->implementation->diagnostics($project);
    }

    /** @return array{kind:string,name:string,detail:string,location:array{file:string,line:int,column:int,length:int,role:string}}|null */
    public function hover(string $project, string $file, int $line, int $column): ?array
    {
        return $this->implementation->hover($project, $file, $line, $column);
    }

    /** @return array{file:string,line:int,column:int,length:int,role:string}|null */
    public function definition(string $project, string $file, int $line, int $column): ?array
    {
        return $this->implementation->definition($project, $file, $line, $column);
    }

    /** @return list<array{file:string,line:int,column:int,length:int,role:string}> */
    public function references(string $project, string $file, int $line, int $column): array
    {
        return $this->implementation->references($project, $file, $line, $column);
    }

    /** @return array{applied:bool,kind:string,previous:string,replacement:string,changes:list<array{file:string,line:int,column:int,length:int,role:string}>,files:list<array{from:string,to:string}>} */
    public function rename(string $project, string $file, int $line, int $column, string $replacement, bool $apply = false): array
    {
        return $this->implementation->rename($project, $file, $line, $column, $replacement, $apply);
    }
}
