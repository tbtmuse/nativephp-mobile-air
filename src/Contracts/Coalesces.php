<?php declare(strict_types=1);

namespace Native\Mobile\Contracts;

interface Coalesces
{
    public function coalesceKey(): string;

    public function coalesceWith(self $other): self;
}
