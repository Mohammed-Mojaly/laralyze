<?php

use Laralyze\Laralyze;
use Laralyze\Recorders\Queries;

function fingerprint(string $sql): string
{
    return app()->make(Queries::class, ['laralyze' => app(Laralyze::class), 'config' => []])->fingerprint($sql);
}

it('folds lists and literals so similar queries share a row', function (string $sql, string $expected) {
    expect(fingerprint($sql))->toBe($expected);
})->with([
    ['select * from "users" where "id" in (?, ?, ?)', 'select * from "users" where "id" in (...)'],
    ['select * from users where id in (1, 2)', 'select * from users where id in (...)'],
    ["select * from users where email = 'sara@example.com'", 'select * from users where email = ?'],
    ['insert into logs (a, b) values (?, ?), (?, ?), (?, ?)', 'insert into logs (a, b) values (...)'],
    ["select   *\n  from posts\n where id = 42", 'select * from posts where id = ?'],
    ['select * from table1 limit 10', 'select * from table1 limit ?'],
]);
