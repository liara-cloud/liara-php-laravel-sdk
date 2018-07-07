<?php

namespace Liara;

use Storage;
use League\Flysystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Liara\Storage\Adapter as StorageAdapter;

class LiaraSDKServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // TODO: Instantiate an http client and pass it to adapters

        Storage::extend('liara', function ($app, $config) {
            return new Filesystem(new StorageAdapter($config));
        });
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
