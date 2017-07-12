<?php

namespace App;

use Config;
use DB;

/**
 * Class SwitchDatabase
 * // @package App\Database
 *
 * this class is renamed from OTF, code is unchanged, added static methods
 * https://lukevers.com/2015/03/25/on-the-fly-database-connections-with-laravel-5
 *
 */

//        $conn = new SwitchDatabase([
//            'driver'   => 'mysql',
//            'database' => 'puppies',
//            'username' => 'jack',
//            'password' => 'the-cute-dog',
//        ]);

class SwitchDatabase {

    /**
     * The name of the database we're connecting to on the fly.
     *
     * @var string $database
     */
    protected $database;

    /**
     * The on the fly database connection.
     *
     * @var \Illuminate\Database\Connection
     */
    protected $connection;

    /**
     * Create a new on the fly database connection.
     *
     * @param  array $options
     */
    public function __construct($options = null)
    {
        // Set the database
        $database = $options['database'];

        // Figure out the driver and get the default configuration for the driver
        $driver  = isset($options['driver']) ? $options['driver'] : Config::get("database.default");
        $default = Config::get("database.connections.$driver");

        // Loop through our default array and update options if we have non-defaults
        foreach($default as $item => $value)
        {
            $default[$item] = isset($options[$item]) ? $options[$item] : $default[$item];
        }

        // Set the temporary configuration
        Config::set("database.connections.$database", $default);

        // Create the connection
        $this->connection = DB::connection($database);
    }

    /**
     * Get the on the fly connection.
     *
     * @return \Illuminate\Database\Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Get a table from the on the fly connection.
     *
     * @var    string $table
     * @return \Illuminate\Database\Query\Builder
     */
    public function getTable($table = null)
    {
        return $this->getConnection()->table($table);
    }

    /**
     * Get connection to database
     *
     * @param array $options
     * @return \Illuminate\Database\Connection
     */
    public static function connect($options = null) {
        $conn = new self($options);
        return $conn->getConnection();
    }

    /**
     * Get connection and return Builder
     *
     * @param array $options
     * @param string $table
     * @return \Illuminate\Database\Query\Builder
     */
    public static function connectTable($options = null, $table = null) {
        $conn = new self($options);
        return $conn->getTable($table);
    }
}