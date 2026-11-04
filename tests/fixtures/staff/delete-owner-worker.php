<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/IsolatedStaffDatabase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Staff\IsolatedStaffDatabase;
use Tests\TestCase;

$test = new class('worker') extends TestCase
{
    public function boot(): void
    {
        $this->app = $this->createApplication();
        $this->withoutVite();
    }
};
$test->boot();
$directory = $argv[1] ?? '';
$role = $argv[2] ?? '';
if (! in_array($role, ['first', 'second'], true) || ! ctype_digit($argv[3] ?? '')) {
    throw new RuntimeException('Invalid staff worker arguments.');
}
IsolatedStaffDatabase::connect($directory);
$actor = User::findOrFail((int) $argv[3]);
file_put_contents($directory.'/'.$role.'-ready', 'ready');
IsolatedStaffDatabase::wait($directory.'/'.$role.'-go');
DB::connection()->beforeExecuting(function ($query) use ($directory, $role): void {
    if (str_starts_with(strtolower($query), 'update "staff_access_guards"')) {
        file_put_contents($directory.'/'.$role.'-attempting', 'attempting staff write lock');
    }
    if (str_starts_with(strtolower($query), 'select * from "users"') && DB::transactionLevel() > 0) {
        file_put_contents($directory.'/'.$role.'-acquired', 'guard acquired');
        if ($role === 'first') {
            IsolatedStaffDatabase::wait($directory.'/release-first');
        }
    }
});
$response = $test->actingAs($actor)->from('/settings/profile')->delete('/settings/profile', ['password' => 'password']);
if ($response->headers->get('Location') === url('/')) {
    $response->assertSessionHasNoErrors();
    echo 'deleted';
} else {
    $response->assertRedirect('/settings/profile')->assertSessionHasErrors('password');
    echo 'refused';
}
