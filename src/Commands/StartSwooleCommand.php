<?php

namespace Laravel\Octane\Commands;

use Illuminate\Support\Str;
use Laravel\Octane\Swoole\ServerProcessInspector;
use Laravel\Octane\Swoole\ServerStateFile;
use Laravel\Octane\Swoole\SwooleExtension;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class StartSwooleCommand extends Command implements SignalableCommandInterface
{
    use Concerns\InteractsWithServers, Concerns\InteractsWithEnvironmentVariables;

    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'octane:swoole
                    {--host=127.0.0.1 : The IP address the server should bind to}
                    {--port=8000 : The port the server should be available on}
                    {--workers=auto : The number of workers that should be available to handle requests}
                    {--task-workers=auto : The number of task workers that should be available to handle tasks}
                    {--max-requests=500 : The number of requests to process before reloading the server}
                    {--watch : Automatically reload the server when the application is modified}';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Start the Octane Swoole server';

    /**
     * Indicates whether the command should be shown in the Artisan command list.
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * Handle the command.
     *
     * @param  \Laravel\Octane\Swoole\ServerProcessInspector  $inspector
     * @param  \Laravel\Octane\Swoole\ServerStateFile  $serverStateFile
     * @param  \Laravel\Octane\Swoole\SwooleExtension  $extension
     * @return int
     */
    public function handle(
        ServerProcessInspector $inspector,
        ServerStateFile $serverStateFile,
        SwooleExtension $extension
    ) {
        if (! $extension->isInstalled()) {
            $this->error('The Swoole extension is missing.');

            return 1;
        }

        if ($inspector->serverIsRunning()) {
            $this->error('Server is already running.');

            return 1;
        }

        $this->writeServerStateFile($serverStateFile, $extension);

        $this->forgetEnvironmentVariables();

        $server = tap(new Process([
            (new PhpExecutableFinder)->find(), 'swoole-server', $serverStateFile->path(),
        ], realpath(__DIR__.'/../../bin'), [
            'APP_ENV' => app()->environment(),
            'APP_BASE_PATH' => base_path(),
            'LARAVEL_OCTANE' => 1,
        ]))->start();

        return $this->runServer($server, $inspector, 'swoole');
    }

    /**
     * Write the Swoole server state file.
     *
     * @param  \Laravel\Octane\Swoole\ServerStateFile  $serverStateFile
     * @param  \Laravel\Octane\Swoole\SwooleExtension  $extension
     * @return void
     */
    protected function writeServerStateFile(
        ServerStateFile $serverStateFile,
        SwooleExtension $extension
    ) {
        $serverStateFile->writeState([
            'appName' => config('app.name', 'Laravel'),
            'host' => $this->option('host'),
            'port' => $this->option('port'),
            'workers' => $this->workerCount($extension),
            'taskWorkers' => $this->taskWorkerCount($extension),
            'maxRequests' => $this->option('max-requests'),
            'publicPath' => public_path(),
            'storagePath' => storage_path(),
            'defaultServerOptions' => $this->defaultServerOptions($extension),
            'octaneConfig' => config('octane'),
        ]);
    }

    /**
     * Get the default Swoole server options.
     *
     * @param  \Laravel\Octane\Swoole\SwooleExtension  $extension
     * @return array
     */
    protected function defaultServerOptions(SwooleExtension $extension)
    {
        return [
            'buffer_output_size' => 10 * 1024 * 1024,
            'enable_coroutine' => false,
            'daemonize' => false,
            'log_file' => storage_path('logs/swoole_http.log'),
            'log_level' => app()->environment('local') ? SWOOLE_LOG_INFO : SWOOLE_LOG_ERROR,
            'max_request' => $this->option('max-requests'),
            'package_max_length' => 10 * 1024 * 1024,
            'reactor_num' => $this->workerCount($extension),
            'send_yield' => true,
            'socket_buffer_size' => 10 * 1024 * 1024,
            'task_max_request' => $this->option('max-requests'),
            'task_worker_num' => $this->taskWorkerCount($extension),
            'worker_num' => $this->workerCount($extension),
        ];
    }

    /**
     * Get the number of workers that should be started.
     *
     * @param  \Laravel\Octane\Swoole\SwooleExtension  $extension
     * @return int
     */
    protected function workerCount(SwooleExtension $extension)
    {
        return $this->option('workers') === 'auto'
                    ? $extension->cpuCount()
                    : $this->option('workers');
    }

    /**
     * Get the number of task workers that should be started.
     *
     * @param  \Laravel\Octane\Swoole\SwooleExtension  $extension
     * @return int
     */
    protected function taskWorkerCount(SwooleExtension $extension)
    {
        return $this->option('task-workers') === 'auto'
                    ? $extension->cpuCount()
                    : $this->option('task-workers');
    }

    /**
     * Write the server process output ot the console.
     *
     * @param  \Symfony\Component\Process\Process  $server
     * @return void
     */
    protected function writeServerOutput($server)
    {
        Str::of($server->getIncrementalOutput())
            ->explode("\n")
            ->filter()
            ->each(fn ($output) => is_array($stream = json_decode($output, true))
                ? $this->handleStream($stream)
                : $this->info($output)
            );

        Str::of($server->getIncrementalErrorOutput())
            ->explode("\n")
            ->filter()
            ->groupBy(fn ($output) => $output)
            ->each(function ($group) {
                is_array($stream = json_decode($output = $group->first(), true))
                    ? $this->handleStream($stream)
                    : $this->error($output);

                if (($count = $group->count()) > 1) {
                    $this->newLine();

                    $count--;

                    $this->line(sprintf('  <fg=red;options=bold>↑</>   %s %s',
                        $count,
                        $count > 1
                            ? 'similar errors were reported.'
                            : 'similar error was reported.'
                    ));
                }
            });
    }

    /**
     * Stop the server.
     *
     * @return void
     */
    protected function stopServer()
    {
        $this->callSilent('octane:stop', [
            '--server' => 'swoole',
        ]);
    }
}
