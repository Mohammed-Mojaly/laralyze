<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Recorders;

class FindingsAuthor extends Model
{
    protected $table = 'authors';

    public $timestamps = false;
}

class FindingsBook extends Model
{
    protected $table = 'books';

    public $timestamps = false;

    /**
     * @return BelongsTo<FindingsAuthor, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(FindingsAuthor::class, 'author_id');
    }
}

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');

    $this->rebootWith(['laralyze.recorders' => [Recorders\Traces::class => ['sample_rate' => 1]]]);
    app()->detectEnvironment(fn () => 'local');

    Schema::dropIfExists('books');
    Schema::dropIfExists('authors');
    Schema::create('authors', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('books', function ($table) {
        $table->id();
        $table->foreignId('author_id');
        $table->string('title');
    });

    foreach (range(1, 6) as $i) {
        $author = DB::table('authors')->insertGetId(['name' => "Author {$i}"]);
        DB::table('books')->insert(['author_id' => $author, 'title' => "Book {$i}"]);
    }
});

afterEach(function () {
    Schema::dropIfExists('books');
    Schema::dropIfExists('authors');
});

/**
 * @return array<string, stdClass>
 */
function findings(string $type): array
{
    return app(Storage::class)->aggregate($type, ['count', 'max'], 3_600)->keyBy('key')->all();
}

it('finds an N+1, where it happens, and how to fix it', function () {
    Route::get('/books', function () {
        foreach (FindingsBook::all() as $book) {
            $book->author->name; // one query per book
        }

        return 'ok';
    });

    $this->get('/books')->assertOk();
    $this->get('/books')->assertOk();
    Laralyze::flush();

    $found = findings('n_plus_one');
    [$sql, $location] = json_decode((string) array_key_first($found), true);

    expect($found)->toHaveCount(1)
        ->and($sql)->toMatch('/from [`"\[]?authors[`"\]]? where [`"\[]?authors[`"\]]?\.[`"\[]?id[`"\]]? = \?/')
        ->and($location)->toContain('FindingsTest.php:')
        ->and((float) reset($found)->count)->toBe(2.0)
        ->and((float) reset($found)->max)->toBe(6.0)
        ->and(findings('duplicate_query'))->toBe([]);

    Livewire::withoutLazyLoading()->test('laralyze.findings')
        ->assertSee('N+1')
        ->assertSee("->with('author')")
        ->assertSee('See an example');

    $uuid = (string) app(Storage::class)->values('finding_example')->first()->value;

    $this->get('/laralyze/executions/'.$uuid)->assertSee('×6');
});

it('finds the same query run again with the same values', function () {
    Route::get('/settings', function () {
        foreach (range(1, 3) as $i) {
            DB::table('authors')->where('name', 'Author 1')->first();
        }

        return 'ok';
    });

    $this->get('/settings');
    Laralyze::flush();

    $found = findings('duplicate_query');

    expect($found)->toHaveCount(1)
        ->and((float) reset($found)->max)->toBe(3.0)
        ->and(findings('n_plus_one'))->toBe([]);

    Livewire::withoutLazyLoading()->test('laralyze.findings')->assertSee('Duplicate')->assertSee('once()');
});

it('recognises reads written by hand', function (string $sql) {
    Route::get('/raw', function () use ($sql) {
        foreach (range(1, 3) as $i) {
            DB::select($sql, ['Author 1']);
        }

        return 'ok';
    });

    $this->get('/raw');
    Laralyze::flush();

    expect(findings('duplicate_query'))->toHaveCount(1);
})->with([
    'leading whitespace' => '
   select * from authors where name = ?',
    'mixed case' => 'Select * From authors where name = ?',
    'a comment first' => '/* settings */ select * from authors where name = ?',
    'a common table expression' => 'with named as (select * from authors where name = ?) select * from named',
]);

it('leaves writes and eager loading alone', function () {
    Route::get('/import', function () {
        foreach (range(10, 16) as $i) {
            DB::table('authors')->insert(['name' => "Author {$i}"]);
        }

        foreach (FindingsBook::with('author')->get() as $book) {
            $book->author->name;
        }

        return 'ok';
    });

    $this->get('/import');
    Laralyze::flush();

    expect(findings('n_plus_one'))->toBe([])->and(findings('duplicate_query'))->toBe([]);

    Livewire::withoutLazyLoading()->test('laralyze.findings')->assertSee('Nothing found');
});

it('still calls it an N+1 when rows share a parent', function () {
    DB::table('books')->update(['author_id' => DB::table('authors')->min('id')]);
    DB::table('books')->where('id', '>', DB::table('books')->min('id') + 2)->update(['author_id' => DB::table('authors')->max('id')]);

    Route::get('/books', function () {
        foreach (FindingsBook::all() as $book) {
            $book->author->name;
        }

        return 'ok';
    });

    $this->get('/books');
    Laralyze::flush();

    expect(findings('n_plus_one'))->toHaveCount(1)->and(findings('duplicate_query'))->toBe([]);
});

it('also finds repeats from before the job or request started', function () {
    $job = tap(Mockery::mock(Job::class), function ($job) {
        $job->shouldReceive('resolveName')->andReturn('App\Jobs\GenerateProfile');
        $job->shouldReceive('payload')->andReturn(['createdAt' => time()]);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('uuid')->andReturn('5b6a2d1e-0000-4000-8000-000000000003');
    });

    event(new Looping('database', 'default'));

    // Settings read again and again while the job is being picked up.
    foreach (range(1, 3) as $i) {
        DB::table('authors')->where('name', 'Author 2')->first();
    }

    event(new JobProcessing('database', $job));
    event(new JobProcessed('database', $job));
    Laralyze::flush();

    $found = findings('duplicate_query');

    expect($found)->toHaveCount(1)
        ->and(json_decode((string) array_key_first($found), true)[1])->toContain('FindingsTest.php:')
        ->and((float) reset($found)->max)->toBe(3.0);
});
