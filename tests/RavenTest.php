<?php

use Jenssegers\Raven\ContextBuilder;

class RavenTest extends Orchestra\Testbench\TestCase
{
    public function setUp()
    {
        $this->dsn = 'https://foo:bar@app.getsentry.com/12345';
        putenv('RAVEN_DSN=' . $this->dsn);

        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return ['Jenssegers\Raven\RavenServiceProvider'];
    }

    public function testBinding()
    {
        $client = $this->app->make('Raven_Client');
        $this->assertInstanceOf('Raven_Client', $client);

        $handler = $this->app->make('Jenssegers\Raven\RavenHandler');
        $this->assertInstanceOf('Jenssegers\Raven\RavenHandler', $handler);
    }

    public function testIsSingleton()
    {
        $handler1 = $this->app->make('Jenssegers\Raven\RavenHandler');
        $handler2 = $this->app->make('Jenssegers\Raven\RavenHandler');
        $this->assertEquals(spl_object_hash($handler1), spl_object_hash($handler2));
    }

    public function testFacade()
    {
        $handler = Jenssegers\Raven\Facades\Raven::getFacadeRoot();
        $this->assertInstanceOf('Jenssegers\Raven\RavenHandler', $handler);
    }

    public function testPassConfiguration()
    {
        $client = $this->app->make('Raven_Client');
        $this->assertEquals('12345', $client->project);
        $this->assertEquals('foo', $client->public_key);
        $this->assertEquals('bar', $client->secret_key);
    }

    public function testCustomConfiguration()
    {
        $this->app->config->set('services.raven.name', 'foo');
        $this->app->config->set('services.raven.site', 'bar');
        $this->app->config->set('services.raven.tags', ['php_version' => phpversion()]);

        $client = $this->app->make('Raven_Client');
        $this->assertEquals('foo', $client->name);
        $this->assertEquals('bar', $client->site);
        $this->assertEquals(['php_version' => phpversion()], $client->tags);
    }

    public function testEnvironmentConfiguration()
    {
        putenv('RAVEN_DSN=https://baz:qux@app.getsentry.com/54321');

        $client = $this->app->make('Raven_Client');
        $this->assertEquals('54321', $client->project);
        $this->assertEquals('baz', $client->public_key);
        $this->assertEquals('qux', $client->secret_key);
    }

    public function testAutomaticContext()
    {
        $this->app->session->set('foo', 'bar');

        $clientMock = Mockery::mock('Raven_Client');
        $clientMock->shouldReceive('captureMessage')->once()->with('Test log message', [], [
            'level' => 'info',
            'user'  => [
                'data' => ['foo' => 'bar'],
                'id'   => $this->app->session->getId(),
            ],
            'tags' => [
                'environment' => 'testing',
                'server'      => 'localhost',
                'php_version' => phpversion(),
            ],
            'extra' => [
                'ip' => '127.0.0.1',
            ],
        ]);

        $handlerMock = Mockery::mock('Jenssegers\Raven\RavenHandler', [$clientMock, new ContextBuilder($this->app)]);
        $handlerMock->shouldReceive('log')->passthru();
        $this->app['Jenssegers\Raven\RavenHandler'] = $handlerMock;

        $handler = $this->app->make('Jenssegers\Raven\RavenHandler');
        $handler->log('info', 'Test log message');
    }

    public function testMergedContext()
    {
        $this->app->session->set('foo', 'bar');

        $clientMock = Mockery::mock('Raven_Client');
        $clientMock->shouldReceive('captureMessage')->once()->with('Test log message', [], [
            'tags' => [
                'environment' => 'testing',
                'server'      => 'localhost',
                'php_version' => phpversion(),
                'one'         => 'two',
            ],
            'user'  => [
                'email' => 'john@doe.com',
                'data'  => ['foo' => 'bar'],
                'id'    => 1337,
            ],
            'level' => 'info',
            'extra' => [
                'ip' => '127.0.0.1',
            ],
        ]);

        $handlerMock = Mockery::mock('Jenssegers\Raven\RavenHandler', [$clientMock, new ContextBuilder($this->app)]);
        $handlerMock->shouldReceive('log')->passthru();
        $this->app['Jenssegers\Raven\RavenHandler'] = $handlerMock;

        $handler = $this->app->make('Jenssegers\Raven\RavenHandler');
        $handler->log('info', 'Test log message', [
            'tags' => ['one' => 'two'],
            'user' => ['id'  => 1337, 'email' => 'john@doe.com'],
        ]);
    }

    public function testAuthData()
    {
        $user = new Illuminate\Foundation\Auth\User();
        $user->id = 123;
        $user->name = 'John Doe';
        $user->username = 'johndoe';
        $user->password = md5('foobar');

        $this->app->auth->login($user);

        $clientMock = Mockery::mock('Raven_Client');
        $clientMock->shouldReceive('captureMessage')->once()->with('Test log message', [], [
            'level' => 'info',
            'user'  => [
                'data' => ['login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => $user->id],
                'id'   => $user->id,
            ],
            'tags' => [
                'environment' => 'testing',
                'server'      => 'localhost',
                'php_version' => phpversion(),
            ],
            'extra' => [
                'ip' => '127.0.0.1',
            ],
        ]);

        $handlerMock = Mockery::mock('Jenssegers\Raven\RavenHandler', [$clientMock, new ContextBuilder($this->app)]);
        $handlerMock->shouldReceive('log')->passthru();
        $this->app['Jenssegers\Raven\RavenHandler'] = $handlerMock;

        $handler = $this->app->make('Jenssegers\Raven\RavenHandler');
        $handler->log('info', 'Test log message');
    }

    public function testEasyExtraData()
    {
        $clientMock = Mockery::mock('Raven_Client');
        $clientMock->shouldReceive('captureMessage')->once()->with('Test log message', [], [
            'extra' => [
                'ip'            => '192.168.0.1',
                'download_size' => 3432425235,
                'foo'           => 'bar',
            ],
            'level' => 'info',
            'tags'  => [
                'environment' => 'testing',
                'server'      => 'localhost',
                'php_version' => phpversion(),
            ],
        ]);

        $handlerMock = Mockery::mock('Jenssegers\Raven\RavenHandler', [$clientMock, new ContextBuilder($this->app)]);
        $handlerMock->shouldReceive('log')->passthru();
        $this->app['Jenssegers\Raven\RavenHandler'] = $handlerMock;

        $handler = $this->app->make('Jenssegers\Raven\RavenHandler');
        $handler->log('info', 'Test log message', [
            'download_size' => 3432425235,
            'ip'            => '192.168.0.1',
            'extra'         => [
                'foo' => 'bar',
            ],
            'level' => 'info',
        ]);
    }

    public function testLogListener()
    {
        $exception = new Exception('Testing error handler');

        $clientMock = Mockery::mock('Raven_Client');
        $clientMock->shouldReceive('captureMessage')->times(2);
        $clientMock->shouldReceive('captureException')->times(1)->with($exception, [
            'level' => 'error',
            'tags'  => [
                'environment' => 'testing',
                'server'      => 'localhost',
                'php_version' => phpversion(),
            ],
            'extra' => [
                'ip' => '127.0.0.1',
            ],
            'level' => 'error',
        ]);

        $handlerMock = Mockery::mock('Jenssegers\Raven\RavenHandler', [$clientMock, new ContextBuilder($this->app)]);
        $handlerMock->shouldReceive('log')->passthru();
        $this->app['Jenssegers\Raven\RavenHandler'] = $handlerMock;

        $this->app->log->info('hello');
        $this->app->log->error('oops');
        $this->app->log->error($exception);
    }

    public function testBelowLevel()
    {
        $this->app->config->set('services.raven.level', 'error');

        $clientMock = Mockery::mock('Raven_Client');
        $clientMock->shouldReceive('captureMessage')->times(0);
        $this->app['Raven_Client'] = $clientMock;

        $this->app->log->info('hello');
        $this->app->log->debug('hello');
        $this->app->log->notice('hello');
        $this->app->log->warning('hello');
    }

    public function testAboveLevel()
    {
        $this->app->config->set('services.raven.level', 'error');

        $clientMock = Mockery::mock('Raven_Client');
        $clientMock->shouldReceive('captureMessage')->times(4);
        $this->app['Raven_Client'] = $clientMock;

        $this->app->log->error('hello');
        $this->app->log->critical('hello');
        $this->app->log->alert('hello');
        $this->app->log->emergency('hello');
    }
}
