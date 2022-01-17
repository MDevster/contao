<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Filesystem\Dbafs\Hashing;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;

/**
 * @experimental
 */
class HashGenerator implements HashGeneratorInterface
{
    private string $hashAlgorithm;
    private bool $useLastModified;

    public function __construct(string $hashAlgorithm, bool $useLastModified = true)
    {
        if (!\in_array($hashAlgorithm, $supportedHashAlgorithms = hash_algos(), true)) {
            throw new \InvalidArgumentException(sprintf("The '%s' hash algorithm isn't available on this system. Try '%s' instead.", $hashAlgorithm, implode("' or '", $supportedHashAlgorithms)));
        }

        $this->hashAlgorithm = $hashAlgorithm;
        $this->useLastModified = $useLastModified;
    }

    public function hashFileContent(VirtualFilesystemInterface $filesystem, string $path, Context $context): void
    {
        if ($this->useLastModified) {
            $context->updateLastModified($filesystem->getLastModified($path, VirtualFilesystemInterface::BYPASS_DBAFS));

            // Skip generating hashes if possible
            if ($context->canSkipHashing() && !$context->lastModifiedChanged()) {
                $context->skipHashing();

                return;
            }
        }

        $context->setHash($this->generateFileContentHash($filesystem, $path));
    }

    public function hashString(string $string): string
    {
        return hash($this->hashAlgorithm, $string);
    }

    private function generateFileContentHash(VirtualFilesystemInterface $filesystem, string $path): string
    {
        $hashContext = hash_init($this->hashAlgorithm);

        hash_update_stream($hashContext, $filesystem->readStream($path));

        return hash_final($hashContext);
    }
}