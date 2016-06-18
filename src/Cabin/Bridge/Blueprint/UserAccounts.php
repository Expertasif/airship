<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Blueprint;

use \Airship\Alerts\Database\QueryError;
use \Airship\Alerts\Security\UserNotFound;
use \Airship\Engine\{
    Bolt\Security as SecurityBolt,
    Security\HiddenString,
    Security\Util,
    State
};
use \ParagonIE\ConstantTime\Base64UrlSafe;
use \ParagonIE\Halite\Symmetric\Crypto as Symmetric;
use \ParagonIE\Halite\Util as CryptoUtil;
use \Psr\Log\LogLevel;
use \ZxcvbnPhp\Zxcvbn;

require_once __DIR__.'/init_gear.php';

/**
 * Class UserAccounts
 *
 * Manage user accounts
 *
 * @package Airship\Cabin\Bridge\Blueprint
 */
class UserAccounts extends BlueprintGear
{
    use SecurityBolt;

    const DEFAULT_MIN_SCORE = 3; // for Zxcvbn
    const RECOVERY_SELECTOR_BYTES = 24;
    const RECOVERY_TOKEN_BYTES = 33;
    const RECOVERY_CHAR_LENGTH = (self::RECOVERY_SELECTOR_BYTES * 4 / 3) + (self::RECOVERY_TOKEN_BYTES * 4 / 3);

    protected $table = 'airship_users';
    protected $grouptable = 'airship_users_groups';
    protected $f = [
        'userid' => 'userid',
        'username' => 'username',
        'uniqueid' => 'uniqueid',
        'password' => 'password',
        'display_name' => 'display_name',
        'real_name' => 'real_name',
        'email' => 'email',
        'birthdate' => 'birthdate',
        'gpg_public_key' => 'gpg_public_key',
        'allow_reset' => 'allow_reset',
        'custom_fields' => 'custom_fields',
        'superuser' => 'superuser',
        'created' => 'created',
        'modified' => 'modified'
    ];

    /**
     * Create a new user group
     *
     * @param array $post
     * @return bool
     */
    public function createGroup(array $post = []): bool
    {
        $this->db->beginTransaction();

        $this->db->insert(
            'airship_groups',
            [
                'name' => $post['name'],
                'inherits' => $post['parent'] ?? null,
                'superuser' => !empty($post['superuser'])
            ]
        );

        return $this->db->commit();
    }

    /**
     * @param int $userID
     * @return string
     */
    public function createRecoveryToken(int $userID): string
    {
        $this->db->beginTransaction();
        $selector = Base64UrlSafe::encode(\random_bytes(static::RECOVERY_SELECTOR_BYTES));
        $token = Base64UrlSafe::encode(\random_bytes(static::RECOVERY_TOKEN_BYTES));
        $hashedToken = CryptoUtil::keyed_hash(
            $token,
            CryptoUtil::raw_hash('' . $userID)
        );
        $this->db->insert(
            'airship_user_recovery',
            [
                'userid' => $userID,
                'selector' => $selector,
                'hashedtoken' => $hashedToken
            ]
        );
        if (!$this->db->commit()) {
            return '';
        }
        return $selector . $token;
    }

    /**
     * @param int $userID
     * @return string
     */
    public function createSessionCanary(int $userID): string
    {
        $canary = Base64UrlSafe::encode(\random_bytes(33));
        $this->db->beginTransaction();
        $this->db->update(
            'airship_users',
            [
                'session_canary' => $canary
            ],
            [
                'userid' => $userID
            ]
        );
        if ($this->db->commit()) {
            return $canary;
        }
        return '';
    }

    /**
     * Create a new user account
     *
     * @param array $post
     * @return int
     */
    public function createUser(array $post = []): int
    {
        $state = State::instance();

        $fingerprint = '';
        if (!empty($post['gpg_public_key'])) {
            try {
                $fingerprint = $state->gpgMailer->import($post['gpg_public_key']);
            } catch (\Crypt_GPG_Exception $ex) {
                // We'll fail silently for now.
            }
        }
        $this->db->insert($this->table, [
            $this->f['username'] =>
                $post['username'],
            $this->f['password'] =>
                $this->airship_auth->createHash(
                    new HiddenString($post['passphrase'])
                ),
            $this->f['uniqueid'] =>
                $this->generateUniqueId(),
            $this->f['email'] =>
                $post['email'] ?? '',
            $this->f['display_name'] =>
                $post['display_name'] ?? '',
            $this->f['allow_reset'] =>
                !empty($post['allow_reset']),
            $this->f['gpg_public_key'] =>
                $fingerprint
        ]);
        $userid = $this->db->cell(
            'SELECT
                '.$this->e($this->f['userid']).'
            FROM
                '.$this->e($this->table).'
            WHERE
                '.$this->e($this->f['username']).' = ?',
            $post['username']
        );

        // Overrideable, but default to "Registered User".
        $default_groups = $state->universal['default-groups'] ?? [2];
        foreach ($default_groups as $grp) {
            $this->db->insert(
                $this->grouptable,
                [
                    'userid' => $userid,
                    'groupid' => $grp
                ]
            );
        }

        // Create preferences record.
        $this->db->insert(
            'airship_user_preferences',
            [
                'userid' => $userid,
                'preferences' => \json_encode($post['preferences'] ?? [])
            ]
        );

        return $userid;
    }

    /**
     * Edit a group.
     *
     * @param int $groupId
     * @param array $post
     * @return bool
     */
    public function editGroup(int $groupId, array $post = []): bool
    {
        if (\in_array($post['parent'], $this->getGroupChildren($groupId))) {
            return false;
        }
        $this->db->beginTransaction();
        $this->db->update(
            'airship_groups',
            [
                'name' => $post['name'],
                'inherits' => !empty($post['parent'])
                    ? $post['parent']
                    : null,
                'superuser' => !empty($post['superuser'])
            ], [
                'groupid' => $groupId
            ]
        );

        return $this->db->commit();
    }

    /**
     * Edit a user's account information
     *
     * @param int $userId
     * @param array $post
     * @return bool
     */
    public function editUser(int $userId, array $post = []): bool
    {
        $this->db->beginTransaction();
        $updates = [];
        foreach (\array_keys($post['groups']) as $i) {
            $post['groups'][$i] += 0;
        }

        $oldGroups = $this->getUsersGroups($userId);
        $delete = \array_diff($oldGroups, $post['groups']);
        $insert = \array_diff($post['groups'], $oldGroups);

        // Manage group changes:
        foreach ($insert as $ins) {
            $this->db->insert(
                $this->grouptable,
                [
                    'userid' => $userId,
                    'groupid' => $ins
                ]
            );
        }
        foreach ($delete as $del) {
            $this->db->delete(
                $this->grouptable,
                [
                    'userid' => $userId,
                    'groupid' => $del
                ]
            );
        }

        foreach (['username', 'uniqueid', 'email', 'display_name', 'real_name'] as $f) {
            $updates[$this->f[$f]] = $post[$f] ?? null;
        }

        if (!empty($post['password'])) {
            $updates[$this->f['password']] = $this->airship_auth->createHash(
                new HiddenString($post['password'])
            );
        }

        $updates['custom_fields'] = \json_encode(\json_decode($post['custom_fields'], true));

        $this->db->update(
            $this->table,
            $updates,
            [
                'userid' => $userId
            ]
        );
        return $this->db->commit();
    }

    /**
     * Get the data necessary to recover an account.
     *
     * @param string $selector
     * @return array
     */
    public function getRecoveryData(string $selector, int $maxTokenLife): array
    {
        if ($maxTokenLife < 10) {
            $maxTokenLife = '0' . $maxTokenLife;
        }
        $dateTime = new \DateTime('now');
        $dateTime->sub(
            new \DateInterval('PT' . $maxTokenLife . 'S')
        );
        $result = $this->db->row(
            'SELECT * FROM airship_user_recovery WHERE selector = ? AND created > ?',
            $selector,
            $dateTime->format('Y-m-d\TH:i:s')
        );
        if (empty($result)) {
            return [];
        }

        // Clean up:
        $this->db->delete(
            'airship_user_recovery',
            [
                'selector' => $selector
            ]
        );
        return $result;
    }

    /**
     * @param int $groupId
     * @return array
     */
    public function getGroup(int $groupId): array
    {
        $group = $this->db->row(
            'SELECT * FROM airship_groups WHERE groupid = ?',
            $groupId
        );
        if (empty($group)) {
            return [];
        }
        return $group;
    }

    /**
     * Get all of the group IDs (not the contents) of a group's children
     *
     * @param int $groupId
     * @return array
     */
    public function getGroupChildren(int $groupId): array
    {
        $group = $this->db->first(
            'SELECT groupid FROM airship_groups WHERE inherits = ?',
            $groupId
        );
        if (empty($group)) {
            return [];
        }
        foreach ($group as $g) {
            foreach ($this->getGroupChildren((int) $g) as $c) {
                \array_unshift($group, $c);
            }
        }
        return $group;
    }

    /**
     * Get the group tree
     *
     * @param int|null $parent
     * @param string $column What to call the child element?
     * @param array $seen
     * @return array
     */
    public function getGroupTree(
        int $parent = 0,
        string $column = 'children',
        array $seen = []
    ): array {
        if ($parent > 0) {
            if (empty($seen)) {
                $groups = $this->db->run(
                    'SELECT * FROM airship_groups WHERE inherits = ? ORDER BY name ASC',
                    $parent
                );
            } else {
                $groups = $this->db->run(
                    'SELECT * FROM airship_groups WHERE groupid NOT IN ' .
                        $this->db->escapeValueSet($seen, 'int') .
                    ' AND inherits = ? ORDER BY name ASC',
                    $parent
                );
            }
        } elseif (empty($seen)) {
            $groups = $this->db->run(
                'SELECT * FROM airship_groups WHERE inherits IS NULL ORDER BY name ASC'
            );
        } else {
            $groups = $this->db->run(
                'SELECT * FROM airship_groups WHERE groupid NOT IN ' .
                    $this->db->escapeValueSet($seen, 'int') .
                ' AND inherits IS NULL ORDER BY name ASC');
        }
        if (empty($groups)) {
            return [];
        }
        foreach ($groups as $i => $grp) {
            $groups[$i][$column] = $this->getGroupTree(
                (int) $grp['groupid'],
                $column,
                $seen
            );
        }
        return $groups;
    }

    /**
     * Get account recovery information
     *
     * @param string $username
     * @return array
     * @throws UserNotFound
     */
    public function getRecoveryInfo(string $username): array
    {
        $userID = $this->getUserIDByUsername($username);
        if (empty($userID)) {
            throw new UserNotFound();
        }
        return $this->db->row(
            'SELECT userid, email, allow_reset, gpg_public_key FROM airship_users WHERE userid = ?',
            $userID
        );
    }

    /**
     * Get the user's two-factor authentication secret
     *
     * @param int $userID
     * @return string
     */
    public function getTwoFactorSecret(int $userID): string
    {
        $secret = $this->db->cell(
            'SELECT totp_secret FROM airship_users WHERE userid = ?',
            $userID
        );
        if (empty($secret)) {
            return '';
        }
        $state = State::instance();
        return Symmetric::decrypt(
            $secret,
            $state->keyring['auth.password_key']
        );
    }

    /**
     * Get a user's unique ID
     *
     * @param int $userId
     * @return string
     * @throws UserNotFound
     */
    public function getUniqueId(int $userId): string
    {
        $unique = $this->db->cell(
            'SELECT '.
                $this->e($this->f['uniqueid']).
            ' FROM '.
                $this->e($this->table).
            ' WHERE '.
                $this->e($this->f['userid']).
            ' = ?',
            $userId
        );
        if (empty($unique)) {
            throw new UserNotFound;
        }
        return $unique;
    }

    /**
     * Get a user account given a user ID
     *
     * @param int $userId
     * @param bool $includeExtra
     * @return array
     */
    public function getUserAccount(int $userId, bool $includeExtra = false): array
    {
        $user = $this->db->row(
            'SELECT
                 *
             FROM
                 '.$this->e($this->table).'
             WHERE
                 '.$this->e($this->f['userid']).' = ?',
            $userId
        );
        if (empty($user)) {
            return [];
        }
        if ($includeExtra) {
            $user['groups'] = $this->getUsersGroups($userId);
            if (!empty($user['custom_fields'])) {
                $user['custom_fields'] = \json_decode($user['custom_fields'], true);
            }
        }
        return $user;
    }

    /**
     * @param string $username
     * @return int
     * @throws UserNotFound
     */
    public function getUserIDByUsername(string $username): int
    {
        $userId = $this->db->cell(
            'SELECT
                 userid
             FROM
                 '.$this->e($this->table).'
             WHERE
                 '.$this->e($this->f['username']).' = ?',
            $username
        );
        if (empty($userId)) {
            throw new UserNotFound();
        }
        return $userId;
    }

    /**
     * Get a user account, given a username
     *
     * @param string $username
     * @param bool $includeExtra
     * @return array
     */
    public function getUserByUsername(string $username, bool $includeExtra = false): array
    {
        try {
            $userId = $this->getUserIDByUsername($username);
        } catch (UserNotFound $ex) {
            return [];
        }
        if (empty($userId)) {
            return [];
        }
        return $this->getUserAccount($userId, $includeExtra);
    }

    /**
     * Get all the groups that a user belongs to.
     *
     * @param int $userId
     * @return array
     */
    public function getUsersGroups(int $userId): array
    {
        $groups =  $this->db->first(
            'SELECT groupid FROM '.$this->e($this->grouptable).' WHERE userid = ?',
            $userId
        );
        if (empty($groups)) {
            return [];
        }
        return $groups;
    }

    /**
     * Get a user's preferences
     *
     * @param int $userId
     * @return array
     */
    public function getUserPreferences(int $userId): array
    {
        $prefs =  $this->db->cell(
            'SELECT preferences FROM airship_user_preferences WHERE userid = ?',
            $userId
        );
        if (empty($prefs)) {
            return [];
        }
        return \json_decode($prefs, true);
    }

    /**
     * All long-term authentication tokens will be rendered invalid.
     * This is usually triggered on a password reset.
     *
     * @param int $userID
     * @return bool
     */
    public function invalidateLongTermAuthTokens(int $userID): bool
    {
        $this->db->beginTransaction();
        $this->db->delete(
            'airship_auth_tokens',
            [
                'userid' => $userID
            ]
        );
        return $this->db->commit();
    }

    /**
     * Is this password too weak?
     *
     * @param array $post
     * @return bool
     */
    public function isPasswordWeak(array $post): bool
    {
        $state = State::instance();
        if (!isset($this->zxcvbn)) {
            $this->zxcvbn = new Zxcvbn;
        }
        $pw = $post['passphrase'];
        $userdata = $post;
        unset($userdata['passphrase']);

        $strength = $this->zxcvbn->passwordStrength($pw, $userdata);

        $min = $state->universal['minimum_password_score'] ?? self::DEFAULT_MIN_SCORE;
        if ($min < 1 || $min > 4) {
            $min = (self::DEFAULT_MIN_SCORE > 4 || self::DEFAULT_MIN_SCORE < 1)
                ? 4
                : self::DEFAULT_MIN_SCORE;
        }

        return $strength['score'] < $min;
    }
    
    /**
     * Is this username invalid? Currently not implemented but might be in the
     * final version.
     * 
     * @param string $username
     * @return bool
     */
    public function isUsernameInvalid(string $username): bool
    {
        return Util::stringLength($username) <= 2;
    }
    
    /**
     * Is the username already taken by another account?
     * 
     * @param string $username
     * @return bool
     * @throws QueryError
     */
    public function isUsernameTaken(string $username): bool
    {
        $num = $this->db->cell(
            'SELECT
                count('.$this->e($this->f['userid']).')
            FROM
                '.$this->e($this->table).'
            WHERE
                '.$this->e($this->f['username']).' = ?',
            $username
        );
        
        // We're expecing an integer, not a boolean
        if ($num === false) {
            throw new QueryError($this->db->errorInfo());
        }
        return $num > 0;
    }

    /**
     * List users
     *
     * @param int $offset
     * @param int $limit
     * @param string $sortBy
     * @param string $dir
     * @return array
     */
    public function listUsers(
        int $offset,
        int $limit,
        string $sortBy = 'userid',
        string $dir = 'ASC'
    ): array {
        $users =  $this->db->run(
            'SELECT ' .
                '* ' .
            ' FROM ' .
                $this->e($this->table).
            ' ORDER BY ' .
                $this->e($sortBy) . ' ' . $dir.
            ' OFFSET ' . $offset .
            ' LIMIT ' . $limit
        );
        if (empty($users)) {
            return [];
        }
        return $users;
    }

    /**
     * How many users exist?
     *
     * @return int
     */
    public function numUsers(): int
    {
        return (int) $this->db->cell(
            'SELECT count(*) FROM ' . $this->e($this->table)
        );
    }


    /**
     * Get the user's two-factor authentication secret
     *
     * @param int $userID
     * @return string
     */
    public function resetTwoFactorSecret(int $userID): bool
    {
        $state = State::instance();
        $this->db->beginTransaction();
        $secret = \random_bytes(20);
        $this->db->update(
            'airship_users',
            [
                'totp_secret' =>
                    Symmetric::encrypt(
                        $secret,
                        $state->keyring['auth.password_key']
                    )
            ], [
                'userid' => $userID
            ]
        );
        return $this->db->commit();
    }

    /**
     * Reset a user's passphrase
     *
     * @param HiddenString $passphrase
     * @param int $accountId
     * @return bool
     */
    public function setPassphrase(HiddenString $passphrase, int $accountId): bool
    {
        $this->db->beginTransaction();
        $this->db->update(
            $this->table,[
                $this->f['password'] =>
                    $this->airship_auth->createHash($passphrase)
            ], [
                $this->f['userid'] =>
                    $accountId
            ]
        );
        return $this->db->commit();
    }

    /**
     * Save the user's two-factor-authentication preferences
     *
     * @param int $userID
     * @param array $post
     * @return bool
     */
    public function toggleTwoFactor(int $userID, array $post): bool
    {
        if (!empty($post['reset_secret'])) {
            $this->resetTwoFactorSecret($userID);
        }
        $this->db->beginTransaction();
        $this->db->update(
            'airship_users',
            [
                'enable_2factor' => !empty($post['enable_two_factor'])
            ],
            [
                'userid' => $userID
            ]
        );
        return $this->db->commit();
    }

    /**
     * Update the user's account information
     *
     * @param array $post
     * @param array $account
     * @return mixed
     */
    public function updateAccountInfo(array $post, array $account)
    {
        $this->db->beginTransaction();
        $state = State::instance();
        $fingerprint = '';
        if (!empty($post['gpg_public_key'])) {
            $fingerprint = $state->gpgMailer->import($post['gpg_public_key']);
            try {
                $fingerprint = $state->gpgMailer->import($post['gpg_public_key']);
            } catch (\Crypt_GPG_Exception $ex) {
                // We'll fail silently for now.
            }
        }
        $post['custom_fields'] = isset($post['custom_fields'])
            ? \json_encode($post['custom_fields'])
            : '[]';

        $this->db->update(
            $this->table, [
                $this->f['display_name'] =>
                    $post['display_name'] ?? '',
                $this->f['email'] =>
                    $post['email'] ?? '',
                $this->f['custom_fields'] =>
                    $post['custom_fields'] ?? '',
                $this->f['gpg_public_key'] =>
                    $fingerprint,
                $this->f['allow_reset'] =>
                    !empty($post['allow_reset']),
            ], [
                $this->f['userid'] =>
                    $account[$this->f['userid']]
            ]
        );
        return $this->db->commit();
    }

    /**
     * Store preferences for a user
     *
     * @param int $userId
     * @param array $preferences
     * @return bool
     */
    public function updatePreferences(int $userId, array $preferences = []): bool
    {
        $this->db->beginTransaction();

        if ($this->db->exists('SELECT count(preferenceid) FROM airship_user_preferences WHERE userid = ?', $userId)) {
            $this->db->update(
                'airship_user_preferences',
                [
                    'preferences' => \json_encode($preferences)
                ],
                [
                    'userid' => $userId
                ]
            );
        } else {
            $this->db->insert(
                'airship_user_preferences', [
                    'userid' => $userId,
                    'preferences' => \json_encode($preferences)
                ]
            );
        }
        return $this->db->commit();
    }

    /**
     * Generate a unique random public ID for this user, which is distinct from the username they use to log in.
     *
     * @return string
     */
    protected function generateUniqueId(): string
    {
        $unique = '';
        $query = 'SELECT count(*) FROM '.$this->e($this->table).' WHERE '.$this->e($this->f['uniqueid']).' = ?';
        do {
            if (!empty($unique)) {
                // This will probably never be executed. It will be a nice easter egg if it ever does.
                $state = State::instance();
                $state->logger->log(
                    LogLevel::ALERT,
                    "A unique user ID collision occurred. This should never happen. (There are 2^192 possible values," .
                        "which has approximately a 50% chance of a single collision occurring after 2^96 users," .
                        "and the database can only hold 2^64). This means you're either extremely lucky or your CSPRNG " .
                        "is broken. We hope it's luck. Airship is clever enough to try again and not fail ".
                        "(so don't worry), but we wanted to make sure you were aware.",
                    [
                        'colliding_random_id' => $unique
                    ]
                );
            }
            $unique = \Airship\uniqueId();
        } while($this->db->cell($query, $unique) > 0);
        return $unique;
    }
}
