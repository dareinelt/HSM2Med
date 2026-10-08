<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\User\User;
use App\User\UserException;
use App\User\UserInput;
use Tests\TestCase;

/**
 * Eingabepruefung der Benutzerverwaltung.
 *
 * Anmeldenamen und Kennwoerter sind der einzige Weg in die Anwendung; die Regeln werden hier
 * ohne Datenbank geprueft.
 */
final class UserInputTest extends TestCase
{
    public function testUsernamesAreNormalized(): void
    {
        $this->assertSame('dr.muster', UserInput::normalizeUsername('  Dr.Muster '));
        $this->assertSame('dr.muster', UserInput::normalizeUsername('DR.MUSTER'));
    }

    public function testValidUsernames(): void
    {
        foreach (['abc', 'admin', 'dr.muster', 'mfa_1', 'anna-b', 'a1b2c3'] as $username) {
            $this->assertTrue(UserInput::usernameIsValid($username), 'Abgelehnt: ' . $username);
        }
    }

    public function testInvalidUsernames(): void
    {
        foreach (['ab', '', '.muster', 'muster.', 'dr muster', 'müller', 'a@b', str_repeat('a', 65)] as $username) {
            $this->assertFalse(UserInput::usernameIsValid($username), 'Akzeptiert: ' . $username);
        }
    }

    public function testPasswordRules(): void
    {
        $this->assertSame([], UserInput::validatePassword('ausreichend-lang'));
        $this->assertTrue(isset(UserInput::validatePassword('kurz')['password']));
        $this->assertTrue(isset(UserInput::validatePassword(str_repeat('a', 201))['password']));
        // Das Kennwort darf nicht dem Anmeldenamen entsprechen (ohne Beachtung der Gross-/Kleinschreibung).
        $this->assertSame(
            ['password' => 'Das Kennwort darf nicht dem Anmeldenamen entsprechen.'],
            UserInput::validatePassword('Dr.Muster-Lang', 'dr.muster-lang'),
        );
        $this->assertSame([], UserInput::validatePassword('Dr.Muster-Lang', 'etwas-anderes'));
    }

    public function testRequirePasswordThrowsOnShortPassword(): void
    {
        $this->assertThrows(
            UserException::class,
            static fn () => UserInput::requirePassword('kurz', 'admin'),
        );
    }

    public function testFromPostAcceptsCompleteInput(): void
    {
        $input = UserInput::fromPost([
            'username' => '  Dr.Muster ',
            'display_name' => "Dr. med.\t Erika   Muster",
            'is_active' => '1',
            'groups' => ['2', 3, '3', 'keine', 0, -4],
        ]);

        $this->assertSame('dr.muster', $input->username);
        $this->assertSame('Dr. med. Erika Muster', $input->displayName);
        $this->assertTrue($input->isActive);
        $this->assertSame([2, 3], $input->groupIds);
        $this->assertSame(
            ['username' => 'dr.muster', 'display_name' => 'Dr. med. Erika Muster', 'is_active' => true, 'groups' => [2, 3]],
            $input->values(),
        );
    }

    public function testFromPostWithoutActiveFlagCreatesDisabledUser(): void
    {
        $input = UserInput::fromPost(['username' => 'anna', 'display_name' => 'Anna Beispiel']);
        $this->assertFalse($input->isActive);
        $this->assertSame([], $input->groupIds);
    }

    public function testFromPostReportsMissingAndInvalidValues(): void
    {
        $error = $this->assertThrows(
            UserException::class,
            static fn () => UserInput::fromPost(['username' => 'ab', 'display_name' => '']),
        );
        $errors = $error->fieldErrors();
        $this->assertTrue(isset($errors['username']), 'Fehler zum Anmeldenamen fehlt.');
        $this->assertTrue(isset($errors['display_name']), 'Fehler zum Anzeigenamen fehlt.');
    }

    public function testFromPostIgnoresNonStringValues(): void
    {
        $error = $this->assertThrows(
            UserException::class,
            static fn () => UserInput::fromPost(['username' => ['admin'], 'display_name' => 42]),
        );
        $this->assertSame(['username', 'display_name'], array_keys($error->fieldErrors()));
    }

    public function testDisplayNameIsLimitedAndCleaned(): void
    {
        $input = UserInput::fromPost([
            'username' => 'anna',
            'display_name' => "Anna\u{0007}Beispiel " . str_repeat('x', 200),
        ]);
        $this->assertNotContains("\u{0007}", $input->displayName);
        $this->assertSame(UserInput::MAX_DISPLAY_NAME, mb_strlen($input->displayName));
    }

    public function testGroupIdsOnlyKeepsPositiveIntegers(): void
    {
        $this->assertSame([], UserInput::groupIds([]));
        $this->assertSame([], UserInput::groupIds('abc'));
        $this->assertSame([5], UserInput::groupIds('5'));
        $this->assertSame([5], UserInput::groupIds(5));
        $this->assertSame([1, 2], UserInput::groupIds([1, '2', 2.9, true, null]));
    }

    public function testUserWithoutGroupsHasNoRights(): void
    {
        $user = new User(1, 'anna', 'Anna', true, [], []);
        $this->assertFalse($user->hasPermission('import'));
        $this->assertFalse($user->mayManageUsers());
        $this->assertSame('', $user->groupText());
        // Ohne Bereichskennung ist der Zugriff frei (z. B. eigenes Kennwort).
        $this->assertTrue($user->hasPermission(null));
        $this->assertTrue($user->hasPermission(''));
    }

    public function testUserWithGroupsCombinesRights(): void
    {
        $user = new User(
            id: 3,
            username: 'dr.muster',
            displayName: 'Dr. med. Erika Muster',
            isActive: true,
            groups: [
                ['id' => 3, 'code' => 'arzt', 'label' => 'Arzt'],
                ['id' => 2, 'code' => 'mfa', 'label' => 'MFA'],
            ],
            permissions: ['dashboard', 'patients'],
        );

        $this->assertTrue($user->hasPermission('patients'));
        $this->assertFalse($user->hasPermission('users'));
        $this->assertFalse($user->mayManageUsers());
        $this->assertSame(['arzt', 'mfa'], $user->groupCodes());
        $this->assertSame('Arzt, MFA', $user->groupText());
    }

    public function testUserFromRowKeepsPasswordOut(): void
    {
        $user = User::fromRow(
            [
                'id' => '4',
                'username' => 'admin',
                'display_name' => 'Administrator',
                'is_active' => 1,
                'last_login_at' => '2026-10-06 09:15:00',
                'created_at' => '2026-10-01 08:00:00',
                'updated_at' => '2026-10-06 09:15:00',
                'password_hash' => '$2y$12$geheim',
            ],
            [['id' => 1, 'code' => 'admin', 'label' => 'Admin']],
            ['users'],
        );

        $this->assertSame(4, $user->id);
        $this->assertTrue($user->isActive);
        $this->assertTrue($user->mayManageUsers());
        $this->assertSame('2026-10-06 09:15:00', $user->lastLoginAt);
        $this->assertFalse(property_exists($user, 'passwordHash'), 'Das Kennwort gehoert nicht in die Anzeige.');
    }
}
