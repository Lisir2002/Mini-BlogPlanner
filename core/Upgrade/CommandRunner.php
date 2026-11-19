<?php

declare(strict_types=1);

namespace Core\Upgrade;

use RuntimeException;

final class CommandRunner
{
    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{code:int,stdout:string,stderr:string}
     */
    public function run(array $command, ?string $cwd = null, array $env = []): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start command.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }
}
