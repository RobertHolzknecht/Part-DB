<?php
/*
    part-db version 0.1
    Copyright (C) 2005 Christoph Lechner
    http://www.cl-projects.de/

    part-db version 0.2+
    Copyright (C) 2009 K. Jacobs and others (see authors.php)
    http://code.google.com/p/part-db/

    This program is free software; you can redistribute it and/or
    modify it under the terms of the GNU General Public License
    as published by the Free Software Foundation; either version 2
    of the License, or (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA
*/

    include_once('start_session.php');

    $messages = array();
    $fatal_error = false; // if a fatal error occurs, only the $messages will be printed, but not the site content
    $starttime = microtime(true); // this is to measure the time while debugging is active

    /********************************************************************************
    *
    *   Evaluate $_REQUEST
    *
    *********************************************************************************/

    $manufacturer_id        = isset($_REQUEST['mid'])               ? (integer)$_REQUEST['mid']             : 0;
    $with_submanufacturers = isset($_REQUEST['subman'])            ? (boolean)$_REQUEST['subman']          : true;
    $table_rowcount     = isset($_REQUEST['table_rowcount'])    ? (integer)$_REQUEST['table_rowcount']  : 0;

    $action = 'default';
    if (isset($_REQUEST['subman_button']))      {$action = 'change_subman_state';}
    $selected_part_id = 0;
    for($i=0; $i<$table_rowcount; $i++)
    {
        $selected_part_id = isset($_REQUEST['id_'.$i]) ? (integer)$_REQUEST['id_'.$i] : 0;

        if (isset($_REQUEST['decrement_'.$i]))
        {
            $action = 'decrement';
            break;
        }

        if (isset($_REQUEST['increment_'.$i]))
        {
            $action = 'increment';
            break;
        }
    }

    /********************************************************************************
    *
    *   Initialize Objects
    *
    *********************************************************************************/

    $html = new HTML($config['html']['theme'], $config['html']['custom_css'], _('Teileansicht'));

    try
    {
        $database           = new Database();
        $log                = new Log($database);
        $current_user       = new User($database, $current_user, $log, 1); // admin

        if ($manufacturer_id < 1)
            throw new Exception(_('Es wurde keine gültige Kategorien-ID übermittelt!'));

        $manufacturer = new Manufacturer($database, $current_user, $log, $manufacturer_id);

        if ($selected_part_id > 0)
            $part = new Part($database, $current_user, $log, $selected_part_id);
        else
            $part = NULL;

        $html->set_title(_('Teileansicht') . ': ' . $manufacturer->get_name());
    }
    catch (Exception $e)
    {
        $messages[] = array('text' => nl2br($e->getMessage()), 'strong' => true, 'color' => 'red');
        $fatal_error = true;
    }

    /********************************************************************************
    *
    *   Execute actions
    *
    *********************************************************************************/

    if ( ! $fatal_error)
    {
        switch ($action)
        {
            case 'change_subcat_state':
                $reload_site = true;
                break;

            case 'decrement': // remove one part
                try
                {
                    if ( ! is_object($part))
                        throw new Exception('Es wurde keine gültige Bauteil-ID übermittelt!');

                    $part->set_instock($part->get_instock() - 1);

                    $reload_site = true;
                }
                catch (Exception $e)
                {
                    $messages[] = array('text' => nl2br($e->getMessage()), 'strong' => true, 'color' => 'red');
                }
                break;

            case 'increment': // add one part
                try
                {
                    if ( ! is_object($part))
                        throw new Exception(_('Es wurde keine gültige Bauteil-ID übermittelt!'));

                    $part->set_instock($part->get_instock() + 1);

                    $reload_site = true;
                }
                catch (Exception $e)
                {
                    $messages[] = array('text' => nl2br($e->getMessage()), 'strong' => true, 'color' => 'red');
                }
                break;
        }
    }

    if (isset($reload_site) && $reload_site && ( ! $config['debug']['request_debugging_enable']))
    {
        // reload the site to avoid multiple actions by manual refreshing
        header('Location: show_manufacturer_parts.php?mid='.$manufacturer_id.'&subcat='.$with_submanufacturers);
    }

    /********************************************************************************
    *
    *   Generate Table
    *
    *********************************************************************************/

    if ( ! $fatal_error)
    {
        try
        {
            $parts = $manufacturer->get_parts($with_submanufacturers, true);
            $table_loop = Part::build_template_table_array($parts, 'manufacturer_parts');
            $html->set_variable('table_rowcount', count($parts), 'integer');
            $html->set_loop('table', $table_loop);
        }
        catch (Exception $e)
        {
            $messages[] = array('text' => nl2br($e->getMessage()), 'strong' => true, 'color' => 'red');
            $fatal_error = true;
        }
    }

    $php_endtime = microtime(true); // For Debug informations

    /********************************************************************************
    *
    *   Set the rest of the HTML variables
    *
    *********************************************************************************/


    $html->set_variable('with_submanufacturers', $with_submanufacturers, 'boolean');

    if ( ! $fatal_error)
    {
        $html->set_variable('mid',                      $manufacturer->get_id(), 'integer');
        $html->set_variable('manufacturer_name',         $manufacturer->get_name(), 'string');
        $html->set_variable('disable_footprints',       ($config['footprints']['disable']), 'boolean');
        $html->set_variable('disable_manufacturers',    ($config['manufacturers']['disable']), 'boolean');
        $html->set_variable('disable_auto_datasheets',  ($config['auto_datasheets']['disable'] ), 'boolean');

        $html->set_variable('use_modal_popup',          $config['popup']['modal'], 'boolean');
        $html->set_variable('popup_width',              $config['popup']['width'], 'integer');
        $html->set_variable('popup_height',             $config['popup']['height'], 'integer');
    }

    /********************************************************************************
    *
    *   Generate HTML Output
    *
    *********************************************************************************/


    //If a ajax version is requested, say this the template engine.
    if(isset($_REQUEST["ajax"]))
    {
        $html->set_variable("ajax_request", true);
    }



    $reload_link = $fatal_error ? 'show_manufacturer_parts.php?mid='.$manufacturer_id  : ''; // an empty string means that the...
    $html->print_header($messages, $reload_link);                                   // ...reload-button won't be visible


    if ( ! $fatal_error)
        $html->print_template('show_manufacturer_parts');

    // If debugging is enabled, print some debug informations
    $debug_messages = array();
    if ((! $fatal_error) && ($config['debug']['enable']))
    {
        $endtime = microtime(true);
        $lifetime = (integer)(1000*($endtime - $starttime));
        $php_lifetime = (integer)(1000*($php_endtime - $starttime));
        $html_lifetime = (integer)(1000*($endtime - $php_endtime));
        $debug_messages[] = array('text' => 'Debug-Meldungen: ', 'strong' => true, 'color' => 'darkblue');
        $debug_messages[] = array('text' => 'Anzahl Teile mit diesem Hersteller: '.(count($parts)), 'color' => 'darkblue');
        $debug_messages[] = array('text' => 'Gesamte Laufzeit: '.$lifetime.'ms', 'color' => 'darkblue');
        $debug_messages[] = array('text' => 'PHP Laufzeit: '.$php_lifetime.'ms', 'color' => 'darkblue');
        $debug_messages[] = array('text' => 'HTML Laufzeit: '.$html_lifetime.'ms', 'color' => 'darkblue');
    }

    $html->print_footer($debug_messages);