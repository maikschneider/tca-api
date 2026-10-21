<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Security;

/**
 * Scopes name what a credential may do: `{resourceName}:read` covers `list`,
 * `show` and `userinfo`, `{resourceName}:write` covers `create`, `update` and
 * `delete`. Either part may be `*`, so `*:read` reads every resource and
 * `articles:*` grants all operations on `articles`.
 */
final class Scope
{
    private const PATTERN = '/^(\*|[a-z0-9][a-z0-9_-]*):(\*|read|write)$/';

    private const READ_OPERATIONS = ['list', 'show', 'userinfo'];

    public static function isValid(mixed $scope): bool
    {
        return \is_string($scope) && preg_match(self::PATTERN, $scope) === 1;
    }

    public static function forOperation(string $resourceName, string $operation): string
    {
        return $resourceName . ':' . (\in_array($operation, self::READ_OPERATIONS, true) ? 'read' : 'write');
    }

    /**
     * A required `{resource}:*` is met by a `*` grant or by read and write granted
     * separately. A required `*:{access}` needs a `*` resource grant, since the
     * resources are not enumerable here.
     *
     * @param list<string> $granted
     */
    public static function grants(array $granted, string $required): bool
    {
        [$requiredResource, $requiredAccess] = explode(':', $required, 2) + [1 => ''];
        if ($requiredAccess === '*') {
            return self::grants($granted, $requiredResource . ':read') && self::grants($granted, $requiredResource . ':write');
        }

        foreach ($granted as $scope) {
            [$resource, $access] = explode(':', $scope, 2) + [1 => ''];
            if (($resource === '*' || $resource === $requiredResource) && ($access === '*' || $access === $requiredAccess)) {
                return true;
            }
        }

        return false;
    }
}
