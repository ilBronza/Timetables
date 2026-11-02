<?php

namespace IlBronza\Timetables\Tests;

use IlBronza\Timetables\Tests\Fixtures\EditorUser;
use IlBronza\Timetables\Tests\Fixtures\Owner;
use IlBronza\Timetables\Tests\Fixtures\OtherOwner;
use IlBronza\Timetables\Tests\Fixtures\ScopedOwner;
use IlBronza\Timetables\TimetablesEditorServiceProvider;
use IlBronza\UikitTemplate\UikitTemplate;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

abstract class EditorTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            \Spatie\Permission\PermissionServiceProvider::class,
            \IlBronza\AccountManager\AccountManagerServiceProvider::class,
            \IlBronza\Ukn\UknServiceProvider::class,
            \IlBronza\Menu\MenuServiceProvider::class,
            \IlBronza\UikitTemplate\UikitTemplateServiceProvider::class,
            \IlBronza\Buttons\ButtonsServiceProvider::class,
            \IlBronza\FormField\FormFieldServiceProvider::class,
            \IlBronza\Form\FormServiceProvider::class,
            \IlBronza\Datatables\DatatablesServiceProvider::class,
            \IlBronza\CRUD\CRUDServiceProvider::class,
            TimetablesEditorServiceProvider::class,
        ]);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.locale', 'it');
        $app['config']->set('app.template', UikitTemplate::class);
        $app['config']->set('menu.serviceProviders', []);
        $app['config']->set('menu.childrenPerColumn', 8);
        $app['config']->set('activitylog.enabled', false);
        $app['config']->set('activitylog.default_auth_driver', null);
        $app['config']->set('auth.providers.users.model', EditorUser::class);
        $app['config']->set('timetables.editor.owners', [
            'companies' => Owner::class, 'other' => OtherOwner::class, 'scoped' => ScopedOwner::class,
        ]);
        $app['config']->set('view.compiled', sys_get_temp_dir().'/timetables-tests-views');
        if (! is_dir($app['config']->get('view.compiled'))) {
            mkdir($app['config']->get('view.compiled'), 0777, true);
        }
    }

    protected function setUp(): void
    {
        foreach ($this->getPackageProviders(null) as $provider) {
            if (! class_exists($provider)) {
                $this->markTestSkipped('The optional CRUD/UIKit suite is required for editor integration tests.');
            }
        }
        parent::setUp();
        foreach (['permissions', 'roles'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
            });
        }
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
        });
        Relation::morphMap([], false);
        Gate::define('view', fn ($user, $owner) => true);
        Gate::define('update', fn ($user, $owner) => true);
        Route::get('/login', fn () => 'login')->name('login');
        Route::getRoutes()->refreshNameLookups();
        $this->actingAs(new EditorUser(['id' => 42, 'name' => 'Editor']));
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        parent::tearDown();
    }
}
