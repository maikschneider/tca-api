<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Configuration;

/**
 * Typed value object for the 'link' key in a column configuration.
 *
 * Presence of this object marks a `type=file` column as accepting links to files
 * that already exist in FAL. Absence means the column rejects them — linking is
 * opt-in because the client names the file by `sys_file` uid, and uids are
 * trivially enumerable. Without a declared scope, an authenticated caller could
 * attach any file in the installation to their own record and read its name and
 * path back out of the response.
 *
 * A file must satisfy both constraints when both are configured: `folders` is a
 * cheap prefix gate on the file's own storage and path, `check` is the caller's
 * own policy. A check can therefore narrow the folder scope but never widen it.
 */
final readonly class LinkDefinition
{
    /**
     * @param list<string>                     $folders FAL folder identifiers ('1:/downloads/'); a file in a
     *                                                  sub-folder qualifies. Empty = no folder constraint.
     * @param array{0: class-string, 1: string}|null $check  [class, method] called with the sys_file row and the
     *                                                  request; must return true for the link to be allowed.
     */
    public function __construct(
        public array $folders = [],
        public ?array $check = null,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on any invalid field.
     */
    public static function fromArray(array $raw): self
    {
        $folders = $raw['folders'] ?? [];
        if (!\is_array($folders) || !array_is_list($folders)) {
            throw new \InvalidArgumentException(
                'Column config "link.folders" must be a list of FAL folder identifiers, e.g. ["1:/downloads/"].',
            );
        }

        $normalized = [];
        foreach ($folders as $folder) {
            if (!\is_string($folder) || !preg_match('#^\d+:/#', $folder)) {
                throw new \InvalidArgumentException(sprintf(
                    'Column config "link.folders" entry "%s" must be a FAL folder identifier like "1:/downloads/".',
                    \is_string($folder) ? $folder : \get_debug_type($folder),
                ));
            }
            $normalized[] = rtrim($folder, '/') . '/';
        }

        $check = $raw['check'] ?? null;
        if ($check !== null && (!\is_array($check) || !\is_string($check[0] ?? null) || !\is_string($check[1] ?? null))) {
            throw new \InvalidArgumentException(
                'Column config "link.check" must be a [class-string, method-string] callable.',
            );
        }

        if ($normalized === [] && $check === null) {
            throw new \InvalidArgumentException(
                'Column config "link" must declare "folders", "check", or both — an empty scope would allow every file in the installation.',
            );
        }

        /** @var array{0: class-string, 1: string}|null $check */
        return new self($normalized, $check);
    }

    /**
     * Whether a file's own storage and identifier fall inside the declared folders.
     */
    public function coversFolder(int $storageUid, string $identifier): bool
    {
        if ($this->folders === []) {
            return true;
        }

        $fileIdentifier = $storageUid . ':' . '/' . ltrim($identifier, '/');

        foreach ($this->folders as $folder) {
            if (str_starts_with($fileIdentifier, $folder)) {
                return true;
            }
        }

        return false;
    }
}
