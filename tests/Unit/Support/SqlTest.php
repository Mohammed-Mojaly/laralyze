<?php

use MohammedMojaly\Laralyze\Support\Sql;

it('colours keywords, strings, numbers, placeholders and identifiers', function () {
    $html = (string) Sql::highlight("select * from `users` where `id` = ? and name = 'x' limit 10");

    expect($html)
        ->toContain('<span class="lz-sql-kw">select</span>')
        ->toContain('<span class="lz-sql-id">`users`</span>')
        ->toContain('<span class="lz-sql-ph">?</span>')
        ->toContain('<span class="lz-sql-str">&#039;x&#039;</span>')
        ->toContain('<span class="lz-sql-num">10</span>');
});

it('escapes everything it prints', function () {
    $html = (string) Sql::highlight('select "<script>alert(1)</script>" from x where y = \'<img src=x onerror=alert(1)>\' and <b>');

    expect($html)->not->toContain('<script>')
        ->not->toContain('<img')
        ->not->toContain('<b>')
        ->toContain('&lt;script&gt;');
});

it('puts each clause on its own line', function () {
    expect(Sql::format('select * from `books` where `parent_id` = ? and `deleted_at` is null order by `id` desc limit 1'))
        ->toBe("select *\nfrom `books`\nwhere `parent_id` = ?\n  and `deleted_at` is null\norder by `id` desc\nlimit 1");
});

it('leaves functions, subqueries and between alone', function () {
    expect(Sql::format('select left(name, 3) from t where id in (select id from u where a = 1) and x between 1 and 2'))
        ->toBe("select left(name, 3)\nfrom t\nwhere id in (select id from u where a = 1)\n  and x between 1 and 2");
});

it('formats inserts, upserts and joins', function () {
    expect(Sql::format('insert into `cache` (`key`, `value`) values (?, ?) on duplicate key update `value` = values(`value`)'))
        ->toBe("insert into `cache` (`key`, `value`)\nvalues (?, ?)\non duplicate key update `value` = values(`value`)");

    expect(Sql::format('select * from a left join b on b.a_id = a.id inner join c on c.id = b.c_id'))
        ->toBe("select *\nfrom a\nleft join b on b.a_id = a.id\ninner join c on c.id = b.c_id");
});

it('keeps text it does not understand intact', function () {
    $sql = "SET SESSION MAX_EXECUTION_TIME = 60000; weird ' unterminated";

    expect(strip_tags(html_entity_decode((string) Sql::highlight($sql), ENT_QUOTES)))->toBe($sql)
        ->and(str_replace("\n", ' ', Sql::format($sql)))->toBe($sql);
});
