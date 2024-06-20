<?php

declare(strict_types=1);

namespace Runtime\FrankenPhpSymfony;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * A runner for FrankenPHP.
 *
 * @author Kévin Dunglas <kevin@dunglas.dev>
 */
class Runner implements RunnerInterface
{
    public function __construct(
        private HttpKernelInterface $kernel,
        private int $loopMax,
        private string $kernelReboot,
    ) {
    }

    public function run(): int
    {
        // Prevent worker script termination when a client connection is interrupted
        ignore_user_abort(true);

        $server = array_filter($_SERVER, static fn (string $key) => !str_starts_with($key, 'HTTP_'), ARRAY_FILTER_USE_KEY);
        $server['APP_RUNTIME_MODE'] = 'web=1&worker=1';

        $handler = function () use ($server, &$sfRequest, &$sfResponse): void {
            // Merge the environment variables coming from DotEnv with the ones tied to the current request
            $_SERVER += $server;

            $sfRequest = Request::createFromGlobals();
            $sfResponse = $this->kernel->handle($sfRequest);

            $sfResponse->send();
        };

        do {
            $ret = \frankenphp_handle_request($handler);

            if ($this->kernel instanceof TerminableInterface && $sfRequest && $sfResponse) {
                $this->kernel->terminate($sfRequest, $sfResponse);
            }

            if (!$ret) {
                return 0;
            }

            if ($this->loopMax > 0 && rand(1, $this->loopMax + 1) % ($this->loopMax + 1) === 0) {
                return 0;
            }

            if ($this->kernel instanceof RebootableInterface && ('always' === $this->kernelReboot)) {
                $this->kernel->reboot(null);
            } elseif ($this->kernel instanceof KernelInterface && $this->kernel->getContainer()->has('services_resetter')) {
                $this->kernel->getContainer()->get('services_resetter')->reset();
            }

            gc_collect_cycles();
        } while (true);
    }
}