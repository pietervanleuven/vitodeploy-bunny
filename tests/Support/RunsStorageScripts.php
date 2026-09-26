<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support;

use App\Facades\SSH;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;

/**
 * Executes the Blade shell scripts recorded by SSH::fake() under a real
 * bash with a fake `curl` on the PATH, so tests can assert exactly which
 * arguments curl receives. This is the only reliable way to prove that
 * hostile values cannot break out of their argument.
 */
trait RunsStorageScripts
{
    /**
     * Run the last executed SSH command and return the curl argv and the
     * script's stdout/stderr.
     *
     * @return array{args: array<int, string>, output: string, exit: int}
     */
    public function runLastScript(string $httpCode = '201'): array
    {
        $commands = SSH::getExecutedCommands();
        $command = end($commands);
        $script = $command instanceof View ? $command->render() : (string) $command;

        $dir = sys_get_temp_dir().'/bunny-script-'.uniqid();
        File::makeDirectory($dir.'/bin', 0755, true);

        $argsFile = $dir.'/curl-args';
        File::put($dir.'/bin/curl', "#!/bin/bash\nprintf '%s\\0' \"\$@\" > \"\$CURL_ARGS_FILE\"\nprintf '%s' \"\$FAKE_HTTP_CODE\"\n");
        chmod($dir.'/bin/curl', 0755);
        File::put($dir.'/script.sh', 'set -e; '.$script);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['bash', $dir.'/script.sh'],
            $descriptors,
            $pipes,
            $dir,
            [
                'PATH' => $dir.'/bin:'.getenv('PATH'),
                'CURL_ARGS_FILE' => $argsFile,
                'FAKE_HTTP_CODE' => $httpCode,
                'TMPDIR' => $dir,
            ]
        );

        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $args = File::exists($argsFile) ? explode("\0", rtrim(File::get($argsFile), "\0")) : [];

        File::deleteDirectory($dir);

        return ['args' => $args, 'output' => $output, 'exit' => $exit];
    }

    /**
     * The value following a curl flag such as -H or -T.
     */
    public function curlArg(array $args, string $flag): ?string
    {
        $index = array_search($flag, $args, true);

        return $index === false ? null : ($args[$index + 1] ?? null);
    }
}
