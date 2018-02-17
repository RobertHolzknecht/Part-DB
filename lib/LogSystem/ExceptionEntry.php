<?php
/**
 * Created by PhpStorm.
 * User: janhb
 * Date: 06.02.2018
 * Time: 18:52
 */

namespace PartDB\LogSystem;

use Exception;
use PartDB\Base\DBElement;
use PartDB\Database;
use PartDB\Log;
use PartDB\User;

class ExceptionEntry extends BaseEntry
{
    /**
     * Constructor
     *
     * @note  It's allowed to create an object with the ID 0 (for the root element).
     *
     * @param Database  &$database      reference to the Database-object
     * @param User      &$current_user  reference to the current user which is logged in
     * @param Log       &$log           reference to the Log-object
     * @param integer   $id             ID of the filetype we want to get
     *
     * @throws Exception    if there is no such attachement type in the database
     * @throws Exception    if there was an error
     */
    public function __construct(&$database, &$current_user, &$log, $id, $db_data = null)
    {
        parent::__construct($database, $current_user, $log, $id, $db_data);

        //Check if we have selcted the right type
        if ($this->getTypeID() != Log::TYPE_EXCEPTION) {
            throw new \RuntimeException(_("Falscher Logtyp!"));
        }
    }


    /**
     * Adds a new log entry to the database.
     * @param $database Database The database which should be used for requests.
     * @param $current_user User The database which should be used for requests.
     * @param $log Log The database which should be used for requests.
     * @param $user User The user that logs in.
     * @param $exception Exception The ip adress the user loggs in from
     *
     * @return static|BaseEntry The new created Entry.
     *
     * @throws Exception
     */
    public static function add(&$database, &$current_user, &$log, $exception)
    {
        return static::addEntry(
            $database,
            $current_user,
            $log,
            Log::TYPE_EXCEPTION,
            Log::LEVEL_ERROR,
            $current_user->getID(),
            Log::TARGET_TYPE_NONE,
            0,
            $exception->getMessage() . " [" . $exception->getFile() . "; " . $exception->getLine() .  "]"
        );
    }

    public function getTargetText()
    {
        return "";
    }

    public function getTargetLink()
    {
        return "";
    }
}