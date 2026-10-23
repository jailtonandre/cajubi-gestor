<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Console\ServeCommand;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LocalUploadEnvironmentTest extends TestCase
{
    public function test_local_windows_server_child_receives_a_writable_temporary_directory(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-specific development server regression.');
        }

        $originalVariables = ServeCommand::$passthroughVariables;
        $originalEnvironment = app()->environment();
        try {
            app()->detectEnvironment(fn (): string => 'local');
            app()->getProvider(AppServiceProvider::class)->boot();
            $environment = [];
            foreach ($_ENV as $key => $value) {
                $environment[$key] = in_array(strtoupper($key), array_map('strtoupper', ServeCommand::$passthroughVariables), true) ? $value : false;
            }
            $child = new Process([PHP_BINARY, '-r', 'echo json_encode(["directory" => sys_get_temp_dir(), "writable" => is_writable(sys_get_temp_dir())]);'], base_path(), $environment);
            $child->mustRun();
            $result = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertTrue($result['writable']);
            $this->assertSame(strtolower(rtrim(sys_get_temp_dir(), '\\/')), strtolower(rtrim($result['directory'], '\\/')));
        } finally {
            ServeCommand::$passthroughVariables = $originalVariables;
            app()->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }
}
