<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitffb6c121b26e5d495a216267f3dbbf1f
{
    public static $prefixLengthsPsr4 = array (
        'P' => 
        array (
            'Paygine\\Controller\\' => 19,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Paygine\\Controller\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src/Controller',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitffb6c121b26e5d495a216267f3dbbf1f::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitffb6c121b26e5d495a216267f3dbbf1f::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitffb6c121b26e5d495a216267f3dbbf1f::$classMap;

        }, null, ClassLoader::class);
    }
}