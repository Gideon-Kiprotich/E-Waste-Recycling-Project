<?php

namespace App\Support\Reporting;

use Closure;
use Illuminate\Http\Response;

class PrivateCsvResponse extends Response
{
    private ?Closure $release = null;

    public function hold(Closure $release): static
    {
        $this->release = $release;

        return $this;
    }

    public function sendContent(): static
    {
        try {
            return parent::sendContent();
        } finally {
            $this->releaseGate();
        }
    }

    public function __destruct()
    {
        $this->releaseGate();
    }

    private function releaseGate(): void
    {
        if ($release = $this->release) {
            $this->release = null;
            $release();
        }
    }
}
