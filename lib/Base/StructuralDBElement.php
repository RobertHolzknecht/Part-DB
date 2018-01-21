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

namespace PartDB\Base;

use Exception;
use Golonka\BBCode\BBCodeParser;
use PartDB\Database;
use PartDB\Exceptions\NotImplementedException;
use PartDB\Exceptions\UserNotAllowedException;
use PartDB\Log;
use PartDB\Part;
use PartDB\Permissions\StructuralPermission;
use PartDB\User;

/**
 * @file class.StructuralDBElement.php
 * @brief class StructuralDBElement
 *
 * All elements with the fields "id", "name" and "parent_id" (at least)
 *
 * This class is for managing all database objects with a structural design.
 * All these sub-objects must have the table columns 'id', 'name' and 'parent_id' (at least)!
 * The root node has always the ID '0'.
 * It's allowed to have instances of root elements, but if you try to change
 * an attribute of a root element, you will get an exception!
 *
 * @class StructuralDBElement
 * @author kami89
 */
abstract class StructuralDBElement extends AttachementsContainingDBElement
{
    /********************************************************************************
     *
     *   Calculated Attributes
     *
     *   Calculated attributes will be NULL until they are requested for first time (to save CPU time)!
     *   After changing an element attribute, all calculated data will be NULLed again.
     *   So: the calculated data will be cached.
     *
     *********************************************************************************/

    /** @var string[] all names of all parent elements as a array of strings,
     *  the last array element is the name of the element itself */
    private $full_path_strings =  null;

    /** @var integer the level of the most top elements is zero */
    private $level =              null;

    /** @var static[] all subelements (not recursive) of this element as a array of objects */
    private $subelements =        null;

    /********************************************************************************
     *
     *   Constructor / Destructor / reset_attributes()
     *
     *********************************************************************************/

    /**
     * Constructor
     *
     * It's allowed to create an object with the ID 0 (for the root element).
     *
     * @param Database  &$database      reference to the Database-object
     * @param User      &$current_user  reference to the current user which is logged in
     * @param Log       &$log           reference to the Log-object
     * @param string    $tablename      name of the table where the elements are located
     * @param integer   $id             ID of the element we want to get
     *
     * @throws Exception        if there is no such element in the database
     * @throws Exception        if there was an error
     */
    public function __construct(&$database, &$current_user, &$log, $tablename, $id, $db_data = null)
    {
        parent::__construct($database, $current_user, $log, $tablename, $id, true, $db_data);

        if ($id == 0) {
            // this is the root node
            $this->db_data['id'] = null;
            $this->db_data['name'] = _('Oberste Ebene');
            $this->db_data['parent_id'] = -1;
            $this->full_path_strings = array(_('Oberste Ebene'));
            $this->level = -1;
        }
    }

    /**
     * @copydoc DBElement::reset_attributes()
     */
    public function resetAttributes($all = false)
    {
        $this->full_path_strings    = null;
        $this->level                = null;
        $this->subelements          = null;

        parent::resetAttributes($all);
    }

    /********************************************************************************
     *
     *   Basic Methods
     *
     *********************************************************************************/

    /**
     * Delete this element
     *
     * @note    This function overrides the same-named method from the parent class.
     *          (Because of the argument $delete_recursive, we need to redefine this method.)
     *
     * @param boolean $delete_recursive             @li if true, all child elements (recursive)
     *                                                  will be deleted too (!!)
     *                                              @li if false, the parent of the child nodes (not recursive)
     *                                                  will be changed to the parent element of this element
     * @param boolean $delete_files_from_hdd        if true, all attached files from this element will be deleted
     *                                                  from harddisc drive (!!)
     *
     * @throws Exception if there was an error
     */
    public function delete($delete_recursive = false, $delete_files_from_hdd = false)
    {
        //Check permission.
        $this->current_user->tryDo($this->getPermissionName(), StructuralPermission::DELETE);
        if ($this->getID() == null) {
            throw new Exception(_('Die Oberste Ebene kann nicht gelöscht werden!'));
        }

        try {
            $transaction_id = $this->database->beginTransaction(); // start transaction

            // first, we take all subelements of this element...
            $subelements = $this->getSubelements(false);

            // then we set $this->subelements to NULL, because if there was an error while deleting
            $this->resetAttributes();

            // ant then we change the parent IDs of the subelments to the parent ID of this element
            foreach ($subelements as $element) {
                if ($delete_recursive) {
                    $element->delete(true, $delete_files_from_hdd);
                } // delete it with all child nodes (!!)
                else {
                    $element->setParentID($this->getParentID());
                } // just change its parent
            }

            // now we can delete this element + all attachements of it
            parent::delete($delete_files_from_hdd);

            $this->database->commit($transaction_id); // commit transaction
        } catch (Exception $e) {
            $this->database->rollback(); // rollback transaction

            // restore the settings from BEFORE the transaction
            $this->resetAttributes();

            throw new Exception(sprintf(_("Das Element \"%s\" konnte nicht gelöscht werden!\nGrund: "), $this->getName()).$e->getMessage());
        }
    }

    public function setAttributes($new_values)
    {
        $arr = array();

        if ($this->current_user->canDo(static::getPermissionName(), StructuralPermission::MOVE)) {
            //Make an exception for $parent_id
            if (isset($new_values['parent_id'])) {
                $arr['parent_id'] = $new_values['parent_id'];
            }
        }
        if ($this->current_user->canDo(static::getPermissionName(), StructuralPermission::EDIT)) {
            //Copy everything except parent_id
            unset($new_values['parent_id']);
            $arr = $arr + $new_values;
        }

        if (empty($arr)) {
            throw new UserNotAllowedException(_("Der aktuelle Benutzer darf die gewünschte Operation nicht durchführen!"));
        }

        parent::setAttributes($arr);
    }

    /**
     * Same like setAttributes(), but without check for permissions.
     * Needed if you want to override the permissions handling in child classes.
     * @throws Exception
     */
    final protected function setAttributesNoCheck($new_values)
    {
        parent::setAttributes($new_values);
    }

    /**
     * Check if this element is a child of another element (recursive)
     *
     * @param Part $another_element       the object to compare
     *                                      IMPORTANT: both objects to compare must be from the same class (for example two "Device" objects)!
     *
     * @return bool
     *
     * @throws Exception if there was an error
     */
    public function isChildOf($another_element)
    {
        if ($this->getID() == null) { // this is the root node
            return false;
        } else {
            $class_name = get_class($this);
            /** @var StructuralDBElement $parent_element */
            $parent_element = new $class_name($this->database, $this->current_user, $this->log, $this->getParentID());

            return (($parent_element->getID() == $another_element->getID()) || ($parent_element->isChildOf($another_element)));
        }
    }

    /********************************************************************************
     *
     *   Getters
     *
     *********************************************************************************/

    public function getName()
    {
        if (!$this->current_user->canDo(static::getPermissionName(), StructuralPermission::READ)) {
            return "???";
        }
        return parent::getName(); // TODO: Change the autogenerated stub
    }

    /**
     * @brief Get the parent-ID
     *
     * @retval integer|null     @li the ID of the parent element
     *                          @li NULL means, the parent is the root node
     *                          @li the parent ID of the root node is -1
     */
    public function getParentID()
    {
        if (!$this->current_user->canDo(static::getPermissionName(), StructuralPermission::READ)) {
            return null;
        }
        return $this->db_data['parent_id'];
    }

    /**
     * Returns the last time when the part was modified.
     * @param $formatted bool When true, the date gets formatted with the locale and timezone settings.
     *          When false, the raw value from the DB is returned.
     * @return string The time of the last edit.
     */
    public function getLastModified($formatted = true)
    {
        if (!$this->current_user->canDo(static::getPermissionName(), StructuralPermission::READ)) {
            return "???";
        }
        return parent::getLastModified($formatted);
    }

    /**
     * Returns the date/time when the part was created.
     * @param $formatted bool When true, the date gets formatted with the locale and timezone settings.
     *       When false, the raw value from the DB is returned.
     * @return string The creation time of the part.
     */
    public function getDatetimeAdded($formatted = true)
    {
        if (!$this->current_user->canDo(static::getPermissionName(), StructuralPermission::READ)) {
            return "???";
        }
        return parent::getDatetimeAdded(true);
    }

    /**
     *  Get the comment of the element.
     *
     * @param boolean $parse_bbcode Should BBCode converted to HTML, before returning
     * @return string       the comment
     */
    public function getComment($parse_bbcode = true)
    {
        if (!$this->current_user->canDo(static::getPermissionName(), StructuralPermission::READ)) {
            return "???";
        }

        $val = htmlspecialchars($this->db_data['comment']);
        if ($parse_bbcode) {
            $bbcode = new BBCodeParser;
            $val = $bbcode->parse($val);
        }

        return $val;
    }

    /**
     * Get the level
     *
     * @note    The level of the root node is -1.
     *
     * @return integer      the level of this element (zero means a most top element
     *                      [a subelement of the root node])
     *
     * @throws Exception if there was an error
     */
    public function getLevel()
    {
        if (!$this->current_user->canDo(static::getPermissionName(), StructuralPermission::READ)) {
            return -1;
        }
        if ($this->level === null) {
            $this->level = 0;
            $parent_id = $this->getParentID();
            $class = get_class($this);
            while ($parent_id > 0) {
                /** @var StructuralDBElement $element */
                $element = new $class($this->database, $this->current_user, $this->log, $parent_id);
                $parent_id = $element->getParentID();
                $this->level++;
            }
        }

        return $this->level;
    }

    /**
     * Get the full path
     *
     * @param string $delimeter     the delimeter of the returned string
     *
     * @return string       the full path (incl. the name of this element), delimeted by $delimeter
     *
     * @throws Exception    if there was an error
     */
    public function getFullPath($delimeter = ' → ')
    {
        if (!$this->current_user->canDo(static::getPermissionName(), StructuralPermission::READ)) {
            return "???";
        }

        if (! is_array($this->full_path_strings)) {
            $this->full_path_strings = array();
            $this->full_path_strings[] = static::getName();
            $parent_id = static::getParentID();
            $class = get_class($this);
            while ($parent_id > 0) {
                /** @var StructuralDBElement $element */
                $element = new $class($this->database, $this->current_user, $this->log, $parent_id);
                $parent_id = $element->getParentID();
                $this->full_path_strings[] = $element->getName();
            }
            $this->full_path_strings = array_reverse($this->full_path_strings);
        }

        return implode($delimeter, $this->full_path_strings);
    }

    /**
     * Get all subelements of this element
     *
     * @param boolean $recursive        if true, the search is recursive
     *
     * @return static[]    all subelements as an array of objects (sorted by their full path)
     *
     * @throws Exception    if there was an error
     */
    public function getSubelements($recursive)
    {
        if (!$this->current_user->canDo(static::getPermissionName(), StructuralPermission::READ)) {
            return array();
        }

        if (! is_array($this->subelements)) {
            $this->subelements = array();

            $query_data = $this->database->query('SELECT * FROM ' . $this->tablename .
                ' WHERE parent_id <=> ? ORDER BY name ASC', array($this->getID()));

            $class = get_class($this);
            foreach ($query_data as $row) {
                $this->subelements[] = new $class($this->database, $this->current_user, $this->log, $row['id'], $row);
            }
        }

        if (! $recursive) {
            return $this->subelements;
        } else {
            $all_elements = array();
            foreach ($this->subelements as $subelement) {
                $all_elements[] = $subelement;
                $all_elements = array_merge($all_elements, $subelement->getSubelements(true));
            }

            return $all_elements;
        }
    }

    /********************************************************************************
     *
     *   Setters
     *
     *********************************************************************************/

    /**
     * Change the parent ID of this element
     *
     * @param integer|null $new_parent_id           @li the ID of the new parent element
     *                                              @li NULL if the parent should be the root node
     *
     * @throws Exception if the new parent ID is not valid
     * @throws Exception if there was an error
     */
    public function setParentID($new_parent_id)
    {
        $this->setAttributes(array('parent_id' => $new_parent_id));
    }

    /**
     *  Set the comment
     *
     * @param string $new_comment       the new comment
     *
     * @throws Exception if there was an error
     */
    public function setComment($new_comment)
    {
        $this->setAttributes(array('comment' => $new_comment));
    }

    /********************************************************************************
     *
     *   Tree / Table Builders
     *
     *********************************************************************************/

    /**
     * Build a HTML tree with all subcategories of this element
     *
     * This method prints a <option>-Line for every item.
     * <b>The <select>-tags are not printed here, you have to print them yourself!</b>
     * Deeper levels have more spaces in front.
     *
     * @param integer   $selected_id    the ID of the selected item
     * @param boolean   $recursive      if true, the tree will be recursive
     * @param boolean   $show_root      if true, the root node will be displayed
     * @param string    $root_name      if the root node is the very root element, you can set its name here
     * @param string    $value_prefix   This string is used as a prefix before the id in the value part of the option.
     *
     * @return string       HTML string if success
     *
     * @throws Exception    if there was an error
     */
    public function buildHtmlTree(
        $selected_id = null,
        $recursive = true,
        $show_root = true,
        $root_name = '$$',
        $value_prefix = ''
    ) {
        if ($root_name=='$$') {
            $root_name=_('Oberste Ebene');
        }

        $html = array();

        if ($show_root) {
            $root_level = $this->getLevel();
            if ($this->getID() > 0) {
                $root_name = htmlspecialchars($this->getName());
            }

            $html[] = '<option value="'. $value_prefix . $this->getID() . '">'. $root_name .'</option>';
        } else {
            $root_level =  $this->getLevel() + 1;
        }

        // get all subelements
        $subelements = $this->getSubelements($recursive);

        foreach ($subelements as $element) {
            $level = $element->getLevel() - $root_level;
            $selected = ($element->getID() == $selected_id) ? 'selected' : '';

            $html[] = '<option '. $selected .' value="'. $value_prefix . $element->getID() . '">';
            for ($i = 0; $i < $level; $i++) {
                $html[] = "&nbsp;&nbsp;&nbsp;";
            }
            $html[] = htmlspecialchars($element->getName()) .'</option>';
        }

        return implode("\n", $html);
    }


    public function buildBootstrapTree(
        $page,
        $parameter,
        $recursive = false,
        $show_root = false,
        $use_db_root_name = true,
        $root_name = '$$'
    ) {
        if ($root_name=='$$') {
            $root_name=_('Oberste Ebene');
        }

        $subelements = $this->getSubelements(false);
        $nodes = array();

        foreach ($subelements as $element) {
            $nodes[] = $element->buildBootstrapTree($page, $parameter);
        }

        // if we are on root level?
        if ($this->getParentID()==-1) {
            if ($show_root) {
                $tree = array(
                    array('text' => ($use_db_root_name) ? htmlspecialchars($this->getName()) : $root_name ,
                        'href' => $page ."?". $parameter ."=".$this->getID(),
                        'nodes' => $nodes)
                );
            } else { //Dont show root node
                $tree = $nodes;
            }
        } else {
            if (!empty($nodes)) {
                $tree = array('text' => htmlspecialchars($this->getName()),
                    'href' => $page ."?". $parameter ."=".$this->getID(),
                    'nodes' => $nodes
                );
            } else {
                $tree = array('text' => htmlspecialchars($this->getName()),
                    'href' => $page ."?". $parameter ."=".$this->getID()
                );
            }
        }


        return $tree;
    }

    /**
     * Creates a template loop for a Breadcrumb bar, representing the structural DB element.
     * @param $page string The base page, to which the breadcrumb links should be directing to.
     * @param $parameter string The parameter, which selects the ID of the StructuralDBElement.
     * @param bool $show_root Show the root as its own breadcrumb.
     * @param string $root_name The label which should be used for the root breadcrumb.
     * @return array An Loop containing multiple arrays, which contains href and caption for the breadcrumb.
     */
    public function buildBreadcrumbLoop($page, $parameter, $show_root = false, $root_name = "$$", $element_is_link = false)
    {
        $breadcrumb = array();

        if ($root_name=='$$') {
            $root_name=_('Oberste Ebene');
        }

        if ($show_root) {
            $breadcrumb[] = array("label" => $root_name,
                "disabled" => true);
        }

        if (!$this->current_user->canDo(static::getPermissionName(), StructuralPermission::READ)) {
            return array("label" =>  "???",
                "disabled" => true);
        }

        $tmp = array();

        if ($element_is_link) {
            $tmp[] = array("label" => static::getName(), 'href' => $page ."?". $parameter ."=".$this->getID(), "selected" => true);
        } else {
            $tmp[] = array("label" => static::getName(), "selected" => true);
        }

        $parent_id = static::getParentID();
        $class = get_class($this);
        while ($parent_id > 0) {
            /** @var StructuralDBElement $element */
            $element = new $class($this->database, $this->current_user, $this->log, $parent_id);
            $parent_id = $element->getParentID();
            $tmp[] = array("label" => $element->getName(), 'href' => $page ."?". $parameter ."=".$element->getID());
        }
        $tmp = array_reverse($tmp);

        $breadcrumb = array_merge($breadcrumb, $tmp);

        return $breadcrumb;
    }


    /********************************************************************************
     *
     *   Static Methods
     *
     *********************************************************************************/

    /**
     * Check if all values are valid for creating a new element / editing an existing element
     *
     * This function is called by creating a new DBElement (DBElement::add()),
     * respectively a subclass of DBElement. Then the attribute $is_new is true!
     *
     * And if you set data fields with DBElement::set_attributes() (or a subclass of DBElement),
     * the new data (one or more attributes) will be checked with this function
     * (with $is_new = false and with the object as $element).
     *
     * Because we pass the values array by reference, you're able to adjust values in the array.
     * For example, you can trim names of elements. So you don't have to throw an Exception if
     * values are not 100% perfect, you simply can "repair" these uncritical attributes.
     *
     * @warning     You have to implement this function in your subclass to check all data!
     *              You should always let to check the parent class all values, and after that,
     *              you can check the values which are associated with your subclass of DBElement.
     *
     * @param Database &$database reference to the database object
     * @param User &$current_user reference to the current user which is logged in
     * @param Log &$log reference to the Log-object
     * @param array &$values @li one-dimensional array of all keys and values (old and new!)
     * @li example: @code
     *                                              array(['name'] => 'abcd', ['parent_id'] => 123, ...) @endcode
     * @param boolean $is_new @li if true, this means we will create a new element.
     * @li if false, this means we will set attributes of an existing element
     * @param static|NULL &$element if $is_new is 'false', we have to supply the element,
     *                                          which will be edited, here.
     *
     * @throws Exception if the values are not valid / the combination of values is not valid
     * @throws Exception if there was an error
     * @throws Exception
     * @throws Exception
     * @throws Exception
     * @throws Exception
     * @throws Exception
     * @throws Exception
     * @throws Exception
     */
    public static function checkValuesValidity(&$database, &$current_user, &$log, &$values, $is_new, &$element = null)
    {
        if ($values['parent_id'] == 0) {
            $values['parent_id'] = null;
        } // NULL is the root node

        // first, we let all parent classes to check the values
        parent::checkValuesValidity($database, $current_user, $log, $values, $is_new, $element);

        if ((! $is_new) && ($values['id'] == 0)) {
            throw new Exception(_('Die Oberste Ebene kann nicht bearbeitet werden!'));
        }

        // with get_called_class() we can get the class of the element which will be edited/created.
        // example: if you write "$new_cat = Category::add(...);", get_called_class() returns "Category"
        $classname = get_called_class();

        // check "parent_id"
        if ((! $is_new) && ($values['parent_id'] == $values['id'])) {
            throw new Exception(_('Ein Element kann nicht als Unterelement von sich selber zugeordnet werden!'));
        }

        try {
            /** @var StructuralDBElement $parent_element */
            $parent_element = new $classname($database, $current_user, $log, $values['parent_id']);
        } catch (Exception $e) {
            debug(
                'warning',
                _('Ungültige "parent_id": "').$values['parent_id'].'"'.
                _("\n\nUrsprüngliche Fehlermeldung: ").$e->getMessage(),
                __FILE__,
                __LINE__,
                __METHOD__
            );
            throw new Exception(_('Das ausgewählte übergeordnete Element existiert nicht!'));
        }

        // to avoid infinite parent_id loops (this is not the same as the "check parent_id" above!)
        if ((! $is_new) && ($parent_element->getParentID() == $values['id'])) {
            throw new Exception(_('Ein Element kann nicht einem seiner direkten Unterelemente zugeordnet werden!'));
        }

        // check "name" + "parent_id" (the first check of "name" was already done by
        // "parent::check_values_validity", here we check only the combination of "parent_id" and "name")
        // we search for an element with the same name and parent ID, there shouldn't be one!
        $id = ($is_new) ? -1 : $values['id'];
        $query_data = $database->query(
            'SELECT * FROM '. $parent_element->getTablename() .
            ' WHERE name=? AND parent_id <=> ? AND id<>?',
            array($values['name'], $values['parent_id'], $id)
        );
        if (count($query_data) > 0) {
            throw new Exception(sprintf(_('Es existiert bereits ein Element auf gleicher Ebene (%1$s::%2$s)'.
                ' mit gleichem Namen (%3$s)!'), $classname, $parent_element->getFullPath(), strip_tags($values['name'])));
        }
    }

    public static function addByArray(&$database, &$current_user, &$log, $tablename, $new_values)
    {
        $current_user->tryDo(static::getPermissionName(), StructuralPermission::CREATE);
        return parent::addByArray($database, $current_user, $log, $tablename, $new_values); // TODO: Change the autogenerated stub
    }

    /**
     * Gets the permission name for control access to this StructuralDBElement
     * @return string The name of the permission for this StructuralDBElement.
     */
    protected static function getPermissionName()
    {
        throw new NotImplementedException(_("getPermissionName() wurde nicht implementiert!"));
    }
}
