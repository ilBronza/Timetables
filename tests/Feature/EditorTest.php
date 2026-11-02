<?php

namespace IlBronza\Timetables\Tests\Feature;

use IlBronza\Timetables\Editor\TimetableEditor;
use IlBronza\Timetables\Models\Timetable;
use IlBronza\Timetables\Models\TimetableInterval;
use IlBronza\Timetables\Services\TimetableWriter;
use IlBronza\Timetables\Tests\EditorTestCase;
use IlBronza\Timetables\Tests\Fixtures\Owner;
use IlBronza\Timetables\Tests\Fixtures\CrudRightsOwner;
use IlBronza\Timetables\Tests\Fixtures\BranchOwner;
use IlBronza\Timetables\Tests\Fixtures\OtherOwner;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;

class EditorTest extends EditorTestCase
{
    private function owner(): Owner
    {
        return Owner::create(['name' => 'Palestra Centrale']);
    }

    private function payload(): array
    {
        return ['kind' => 'opening', 'name' => 'Apertura palestra', 'timezone' => 'Europe/Rome', 'enabled' => '1', 'complete' => '1', 'periods' => [
            ['name' => 'Inverno', 'valid_from' => '2026-10-01', 'valid_to' => '2026-12-31',
                'weekly' => [1 => [
                    ['start' => '09:00', 'end' => '13:00', 'next_day' => '0'],
                    ['start' => '22:00', 'end' => '02:00', 'next_day' => '1'],
                ], 2 => ''],
                'exceptions' => [
                    ['date' => '2026-12-25', 'intervals' => ''],
                    ['date' => '2026-12-24', 'intervals' => [['start' => '09:00', 'end' => '12:00', 'next_day' => '0']]],
                ],
            ],
        ]];
    }

    private function createTimetable(Owner $owner): Timetable
    {
        return app(TimetableWriter::class)->replace($owner, 'opening', [
            'name' => 'Orario esistente', 'timezone' => 'Europe/Rome', 'periods' => [
                ['weekly' => [1 => [[540, 780], [1320, 1560]]], 'exceptions' => ['2026-12-25' => []]],
            ],
        ]);
    }

    public function test_full_uikit_pages_render_create_read_update_and_list(): void
    {
        $owner = $this->owner();
        $this->get(TimetableEditor::url($owner))->assertOk()->assertSee('Nessun orario configurato.');
        $this->get(TimetableEditor::url($owner, 'create'))->assertOk()
            ->assertSee('uikit', false)->assertSee('Lunedì')->assertSee('Termina il giorno dopo');
        $record = $this->createTimetable($owner);
        $this->get(TimetableEditor::url($owner, 'edit', $record))->assertOk()
            ->assertSee('value="22:00"', false)->assertSee('value="02:00"', false)
            ->assertSee('name="revision" value="1"', false)->assertSee('2026-12-25');
        $this->get(TimetableEditor::url($owner, 'show', $record))->assertOk()
            ->assertSee('fieldset class="uk-fieldset" disabled', false)->assertDontSee('type="submit"', false);
        $this->get(TimetableEditor::url($owner).'?embedded=1')->assertOk()
            ->assertSee('Orario esistente')->assertDontSee('<html', false);
    }

    public function test_create_round_trips_overnight_intervals_closures_and_multiple_periods(): void
    {
        $owner = $this->owner();
        $payload = $this->payload();
        $payload['periods'][8] = ['valid_from' => '2027-01-01', 'valid_to' => null, 'weekly' => [2 => [['start' => '08:00', 'end' => '08:00', 'next_day' => '1']]], 'exceptions' => ''];
        $this->post(TimetableEditor::url($owner, 'store'), $payload)->assertRedirect();
        $record = $owner->timetables()->with('periods.days.intervals')->firstOrFail();
        $this->assertSame(1, $record->revision);
        $this->assertSame(2, $record->periods->count());
        $this->assertTrue($record->enabled);
        $this->assertSame(1560, $record->periods[0]->days->firstWhere('weekday', 1)->intervals[1]->end_minute);
        $this->assertCount(0, $record->periods[0]->days->firstWhere('on_date', '2026-12-25')->intervals);
        $this->assertSame(1920, $record->periods[1]->days->firstWhere('weekday', 2)->intervals[0]->end_minute);
        $this->assertTrue($owner->timetable('opening')->isActiveAt('2026-10-13T01:00:00+02:00'));
    }

    public function test_update_replaces_aggregate_and_allows_clearing_every_period(): void
    {
        $owner = $this->owner();
        $record = $this->createTimetable($owner);
        $payload = $this->payload();
        unset($payload['kind']);
        $payload['revision'] = 1;
        $payload['enabled'] = '0';
        $payload['periods'] = '';
        $this->put(TimetableEditor::url($owner, 'update', $record), $payload)
            ->assertRedirect(TimetableEditor::url($owner, 'edit', $record));
        $this->assertSame(2, $record->fresh()->revision);
        $this->assertFalse($record->fresh()->enabled);
        $this->assertSame(0, $record->periods()->count());
        $this->assertSame(0, TimetableInterval::count());
    }

    public function test_soft_delete_restore_and_stale_delete_are_revision_checked(): void
    {
        $owner = $this->owner();
        $record = $this->createTimetable($owner);
        $this->delete(TimetableEditor::url($owner, 'destroy', $record), ['revision' => 1])->assertRedirect();
        $this->assertTrue($record->fresh()->trashed());
        $this->get(TimetableEditor::url($owner))->assertOk()->assertSee('Ripristina');
        $this->get(TimetableEditor::url($owner, 'edit', $record))->assertNotFound();
        $this->postJson(TimetableEditor::url($owner, 'restore', $record), ['revision' => 1])->assertStatus(409);
        $this->post(TimetableEditor::url($owner, 'restore', $record), ['revision' => 2])->assertRedirect();
        $this->assertFalse($record->fresh()->trashed());
        $this->assertSame(3, $record->fresh()->revision);
        $this->delete(TimetableEditor::url($owner, 'destroy', $record), ['revision' => 1])
            ->assertStatus(409)->assertSee('Carica i dati aggiornati');
        $this->assertFalse($record->fresh()->trashed());
    }

    public function test_stale_update_keeps_input_and_original_revision_without_overwriting(): void
    {
        $owner = $this->owner();
        $record = $this->createTimetable($owner);
        app(TimetableWriter::class)->setEnabled($owner, 'opening', false, 1);
        $payload = $this->payload();
        unset($payload['kind']);
        $payload['name'] = 'Modifica da conservare';
        $payload['revision'] = 1;
        $response = $this->put(TimetableEditor::url($owner, 'update', $record), $payload);
        $response->assertStatus(409)->assertSee('Modifica da conservare')
            ->assertSee('name="revision" value="1"', false)->assertSee('type="submit" disabled', false);
        $this->assertSame(2, $record->fresh()->revision);
        $this->assertSame('Orario esistente', $record->fresh()->name);
        $this->assertFalse($record->fresh()->enabled);
    }

    public function test_duplicate_create_and_update_after_delete_do_not_resurrect_records(): void
    {
        $owner = $this->owner();
        $record = $this->createTimetable($owner);
        $this->post(TimetableEditor::url($owner, 'store'), $this->payload())->assertStatus(409);
        app(TimetableWriter::class)->delete($owner, 'opening', 1);
        $payload = $this->payload();
        unset($payload['kind']);
        $payload['revision'] = 2;
        $this->putJson(TimetableEditor::url($owner, 'update', $record), $payload)->assertStatus(409);
        $this->assertTrue($record->fresh()->trashed());
        $this->assertSame(1, Timetable::withTrashed()->count());
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_never_writes_partial_data(string $path, mixed $value, string $error): void
    {
        $owner = $this->owner();
        $payload = $this->payload();
        data_set($payload, $path, $value);
        $this->postJson(TimetableEditor::url($owner, 'store'), $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertSame(0, Timetable::withTrashed()->count());
        $this->assertSame(0, TimetableInterval::count());
    }

    public static function invalidInputs(): array
    {
        return [
            'incomplete form' => ['complete', null, 'complete'],
            'timezone outside allowlist' => ['timezone', 'Asia/Tokyo', 'timezone'],
            'bad clock' => ['periods.0.weekly.1.0.start', '25:30', 'periods.0.weekly.1.0.start'],
            'midnight flag missing' => ['periods.0.weekly.1.1.next_day', null, 'periods.0.weekly.1.1.next_day'],
            'inverted time' => ['periods.0.weekly.1.1.next_day', '0', 'periods.0.weekly.1.1.end'],
            'too long overnight' => ['periods.0.weekly.1.0.next_day', '1', 'periods.0.weekly.1.0.end'],
            'bad date' => ['periods.0.valid_from', '2026-02-30', 'periods.0.valid_from'],
            'inverted period' => ['periods.0.valid_to', '2026-09-30', 'periods.0.valid_to'],
            'overlapping periods' => ['periods.1', ['valid_from' => '2026-12-31', 'weekly' => [], 'exceptions' => ''], 'periods.1.valid_from'],
            'duplicate date' => ['periods.0.exceptions.1.date', '2026-12-25', 'periods.0.exceptions.1.date'],
            'exception out of bounds' => ['periods.0.exceptions.1.date', '2027-01-01', 'periods.0.exceptions.1.date'],
            'child id injection' => ['periods.0.id', 'foreign-period', 'periods.0'],
            'bad weekday' => ['periods.0.weekly.8', [], 'periods.0.weekly'],
            'revision supplied on create' => ['revision', 1, 'revision'],
        ];
    }

    public function test_validation_redirect_retains_nested_form_values(): void
    {
        $owner = $this->owner();
        $url = TimetableEditor::url($owner, 'create');
        $data = $this->payload();
        $data['_timetables_form'] = TimetableEditor::url($owner, 'store');
        $data['periods'][0]['weekly'][1][0]['end'] = '08:00';
        $this->from($url)->post(TimetableEditor::url($owner, 'store'), $data)->assertRedirect($url)->assertSessionHasErrors();
        $this->get($url)->assertOk()->assertSee('value="08:00"', false)->assertSee('Apertura palestra')->assertSee('2026-12-24');
    }

    public function test_owner_allowlist_global_scopes_soft_deletes_and_record_ownership(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $record = $this->createTimetable($owner);
        $this->get('/timetables-management/IlBronza%5CTimetables%5CTests%5CFixtures%5COwner/'.$owner->id)->assertNotFound();
        $this->get(TimetableEditor::url($other, 'show', $record))->assertNotFound();
        $this->putJson(TimetableEditor::url($other, 'update', $record), [])->assertNotFound();
        $this->delete(TimetableEditor::url($other, 'destroy', $record), ['revision' => 1])->assertNotFound();
        $this->post(TimetableEditor::url($other, 'restore', $record), ['revision' => 1])->assertNotFound();
        $differentType = OtherOwner::create(['name' => 'Other type']);
        $this->get(TimetableEditor::url($differentType, 'show', $record))->assertNotFound();
        $hidden = Owner::create(['name' => 'Another tenant', 'tenant_id' => 2]);
        $this->get('/timetables-management/scoped/'.$hidden->id)->assertNotFound();
        $owner->delete();
        $this->get(TimetableEditor::url($owner))->assertNotFound();
        $this->assertSame(1, $record->fresh()->revision);
    }

    public function test_view_only_permissions_hide_mutations_and_block_every_write_route(): void
    {
        $owner = $this->owner();
        $record = $this->createTimetable($owner);
        Gate::define('update', fn () => false);
        $this->get(TimetableEditor::url($owner))->assertOk()->assertDontSee('Crea orario')->assertDontSee('type="submit"', false);
        $this->get(TimetableEditor::url($owner, 'show', $record))->assertOk()->assertDontSee('Modifica orario');
        $this->get(TimetableEditor::url($owner, 'create'))->assertForbidden();
        $this->get(TimetableEditor::url($owner, 'edit', $record))->assertForbidden();
        $this->postJson(TimetableEditor::url($owner, 'store'), $this->payload())->assertForbidden();
        $this->putJson(TimetableEditor::url($owner, 'update', $record), [])->assertForbidden();
        $this->delete(TimetableEditor::url($owner, 'destroy', $record), ['revision' => 1])->assertForbidden();
        $this->post(TimetableEditor::url($owner, 'restore', $record), ['revision' => 1])->assertForbidden();
        Gate::define('view', fn () => false);
        $this->get(TimetableEditor::url($owner))->assertForbidden();
        $this->get(TimetableEditor::url($owner, 'show', $record))->assertForbidden();
    }

    public function test_guests_are_rejected_and_policy_denial_overrides_crud_rights(): void
    {
        $owner = $this->owner();
        Gate::policy(Owner::class, DenyOwnerPolicy::class);
        $this->get(TimetableEditor::url($owner))->assertForbidden();
        auth()->forgetGuards();
        $this->get(TimetableEditor::url($owner))->assertRedirect('/login');
        $this->postJson(TimetableEditor::url($owner, 'store'), $this->payload())->assertUnauthorized();
    }

    public function test_crud_rights_are_supported_and_owner_policy_takes_precedence(): void
    {
        config(['timetables.editor.owners.crud' => CrudRightsOwner::class]);
        $owner = CrudRightsOwner::create(['name' => 'CRUD owner']);
        $url = TimetableEditor::url($owner);
        $this->assertStringContainsString('/crud/', $url);
        $this->get($url)->assertOk()->assertDontSee('Crea orario');
        $this->get(TimetableEditor::url($owner, 'create'))->assertForbidden();
        Gate::policy(CrudRightsOwner::class, DenyOwnerPolicy::class);
        $this->get($url)->assertForbidden();
    }

    public function test_malformed_flashed_input_still_renders_validation_errors(): void
    {
        $owner = $this->owner();
        $payload = $this->payload();
        $payload['name'] = ['invalid'];
        $payload['periods'][0]['weekly'] = 'invalid';
        $payload['_timetables_form'] = TimetableEditor::url($owner, 'store');
        $url = TimetableEditor::url($owner, 'create');
        $this->from($url)->post(TimetableEditor::url($owner, 'store'), $payload)->assertRedirect($url)->assertSessionHasErrors();
        $this->get($url)->assertOk()->assertSee('Correggi i campi indicati');
    }

    public function test_morph_map_is_used_for_storage_and_fetcher_integrates_with_crud_form(): void
    {
        Relation::morphMap(['company' => Owner::class], false);
        $owner = $this->owner();
        $this->post(TimetableEditor::url($owner, 'store'), $this->payload())->assertRedirect();
        $record = $owner->timetables()->firstOrFail();
        $this->assertSame('company', $record->owner_type);
        $this->get(TimetableEditor::url($owner, 'edit', $record))->assertOk();
        $form = \IlBronza\Form\Form::createFromArray(['action' => '/', 'method' => 'PUT']);
        $this->assertSame($form, TimetableEditor::addFetcher($form, $owner));
        $this->assertCount(1, $form->getFetchersPosition('outherBottom'));
        $this->get($form->getFetchersPosition('outherBottom')->first()->getUrl())
            ->assertOk()->assertSee('Apertura palestra')->assertDontSee('<html', false);
    }

    public function test_web_editor_keeps_the_owner_connection_including_prefixed_tables(): void
    {
        config(['database.connections.branch' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'branch_', 'foreign_key_constraints' => true,
        ], 'timetables.editor.owners.branch' => BranchOwner::class]);
        DB::connection('branch')->getSchemaBuilder()->create('owners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        $original = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection('branch');
            $migration = require glob(__DIR__.'/../../database/migrations/*.php')[0];
            $migration->up();
        } finally {
            DB::setDefaultConnection($original);
        }
        $owner = BranchOwner::create(['name' => 'Branch company']);
        $this->post(TimetableEditor::url($owner, 'store'), $this->payload())->assertRedirect();
        $record = $owner->timetables()->firstOrFail();
        $this->get(TimetableEditor::url($owner, 'edit', $record))->assertOk()->assertSee('value="22:00"', false);
        $this->delete(TimetableEditor::url($owner, 'destroy', $record), ['revision' => 1])->assertRedirect();
        $this->assertTrue($record->fresh()->trashed());
        $this->assertSame(0, Timetable::withTrashed()->count());
        DB::purge('branch');
    }

    public function test_rendered_form_submits_clock_values_and_empty_lists_without_losing_data(): void
    {
        $owner = $this->owner();
        $record = $this->createTimetable($owner);
        $html = $this->get(TimetableEditor::url($owner, 'edit', $record))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $query = [];
        foreach ($xpath->query('//form[@data-timetable-editor]//input[@name and not(ancestor::template)] | //form[@data-timetable-editor]//select[@name and not(ancestor::template)]') as $input) {
            if ($input->getAttribute('type') === 'checkbox' && ! $input->hasAttribute('checked')) {
                continue;
            }
            $value = $input->getAttribute('value');
            if ($input->tagName === 'select') {
                $value = $xpath->query('option[@selected]', $input)->item(0)->getAttribute('value');
            }
            $query[] = urlencode($input->getAttribute('name')).'='.urlencode($value);
        }
        parse_str(implode('&', $query), $payload);
        $payload['name'] = 'Browser form';
        $this->post(TimetableEditor::url($owner, 'update', $record), $payload)->assertRedirect();
        $fresh = $record->fresh()->load('periods.days.intervals');
        $this->assertSame('Browser form', $fresh->name);
        $this->assertSame(2, $fresh->revision);
        $this->assertSame(1560, $fresh->periods[0]->days->firstWhere('weekday', 1)->intervals[1]->end_minute);
        $this->assertCount(0, $fresh->periods[0]->days->firstWhere('on_date', '2026-12-25')->intervals);
    }

    public function test_missing_revision_and_renaming_kind_cannot_overwrite_the_aggregate(): void
    {
        $owner = $this->owner();
        $record = $this->createTimetable($owner);
        $payload = $this->payload();
        $this->putJson(TimetableEditor::url($owner, 'update', $record), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['kind', 'revision']);
        $this->assertSame(1, $record->fresh()->revision);
    }

    public function test_list_paginates_with_uikit_and_excludes_other_owners(): void
    {
        $owner = $this->owner();
        $writer = app(TimetableWriter::class);
        foreach (range(1, 21) as $i) {
            $writer->replace($owner, sprintf('opening-%02d', $i), ['name' => 'Visible '.$i, 'timezone' => 'UTC', 'periods' => []]);
        }
        $writer->replace($this->owner(), 'other', ['name' => 'Another owner secret', 'timezone' => 'UTC', 'periods' => []]);
        $this->get(TimetableEditor::url($owner).'?embedded=1')->assertOk()
            ->assertSee('uk-pagination', false)->assertSee('Visible 20')->assertDontSee('Visible 21')
            ->assertDontSee('Another owner secret')->assertSee('embedded=1&amp;page=2', false);
        $this->get(TimetableEditor::url($owner).'?embedded=1&page=2')->assertOk()->assertSee('Visible 21')->assertDontSee('Visible 20');
    }

    public function test_edit_retries_when_another_write_commits_during_aggregate_loading(): void
    {
        $owner = $this->owner();
        $record = $this->createTimetable($owner);
        $fired = false;
        Timetable::retrieved(function (Timetable $header) use (&$fired, $owner): void {
            if ($fired) {
                return;
            }
            $fired = true;
            app(TimetableWriter::class)->replace($owner, 'opening', [
                'name' => 'Latest version', 'timezone' => 'Europe/Rome', 'periods' => [['weekly' => [1 => [[600, 660]]]]],
            ], $header->revision);
        });
        try {
            $this->get(TimetableEditor::url($owner, 'edit', $record))->assertOk()
                ->assertSee('Latest version')->assertSee('value="10:00"', false)->assertSee('name="revision" value="2"', false);
            $this->assertTrue($fired);
        } finally {
            Timetable::flushEventListeners();
        }
    }
}

class DenyOwnerPolicy
{
    public function view($user, $owner): bool
    {
        return false;
    }
}
