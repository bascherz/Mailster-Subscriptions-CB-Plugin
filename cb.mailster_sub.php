<?php
/**
    Name:    CB Mailster Subscriptions
    Version: 1.0, native for Joomla 4
    Date:    November 2022
    Author:  Bruce Scherzinger
    Email:   joomlander@scherzinger.org
    URL:     http://joomla.org
    Purpose: Community Builder tab to allow subscription control of Mailster lists in member profiles.

    License: GNU/GPL
    This is free software. This version may have been modified pursuant
    to the GNU General Public License, and as distributed it includes or
    is derivative of works licensed under the GNU General Public License or
    other free or open source software licenses.
    (C) 2007-2017 Bruce Scherzinger

    Mailster is Free and Paid Software released under the Gnu Public License.
    https://www.brandt-oss.com
*/
defined('_JEXEC') or die('Direct Access to this location is not allowed.');

// Language-specific strings
// Error pop-ups
define("CHANGES_NOT_SAVED","CHANGES NOT SAVED! If you receive an email to the contrary, don't believe it.");
define("ADDRESS_ALREADY_IN_USE","One or more email addresses entered already in use by another member!");
define("DUPLICATE_ADDRESS_ENTERED","Email address cannot be used more than once per member!");

// Default messages (if not specified in back end)
define("DEFAULT_UNSUBSCRIBE_MSG","successfully unsubscribed [EMAIL] from [LIST] email list at [SITE].");
define("DEFAULT_SUBSCRIBE_MSG","successfully subscribed [EMAIL] to [LIST] email list at [SITE].");
define("DEFAULT_AUTOSUBSCRIBE_MSG","[EMAIL] automatically subscribed to [LIST] email list at [SITE].");
define("DEFAULT_ADDRESS_CHANGE_MSG","successfully changed [OLD] to [EMAIL].");
define("DEFAULT_EMAIL_SUBJECT","[SITE] Email List Subscription Update");
define("ALL_EMAIL_LISTS","all email lists");

const FETCH      = 1;
const DONT_FETCH = 0;

// These registrations handle administrator modifications to user settings.
$_PLUGINS->registerFunction('onUserActive',                 'afterUserActivated',   'getMailsterTab');
$_PLUGINS->registerFunction('onBeforeUserUpdate',           'beforeUserUpdate',     'getMailsterTab');
$_PLUGINS->registerFunction('onBeforeUpdateUser',           'beforeUserUpdate',     'getMailsterTab');
$_PLUGINS->registerFunction('onAfterUserUpdate',            'afterUserUpdate',      'getMailsterTab');
$_PLUGINS->registerFunction('onAfterUpdateUser',            'afterUserUpdate',      'getMailsterTab');
$_PLUGINS->registerFunction('onBeforeDeleteUser',           'beforeDeleteUser',     'getMailsterTab');
$_PLUGINS->registerFunction('onAfterLogin',                 'afterLogin',           'getMailsterTab');
$_PLUGINS->registerFunction('onBeforeUserProfileDisplay',   'beforeProfileDisplay', 'getMailsterTab');

class userEmailObj
{
    public $label;  // label on email field
    public $field;  // field name
    public $email;  // email address extracted from field
    public $name;   // name associated with the address
    public $index;  // array index (superfluous)
}

class getMailsterTab extends cbTabHandler
{
    function __construct()
    {
        $this->cbTabHandler();
    }

    /******* EVENT HANDLERS *******/

    function afterLogin($user, $dontcare)
    {
        // Since the onBeforeUserProfileEditDisplay event doesn't update the display AFTER handling user events (it does so BEFORE),
        // update the profile fields at login so that by the time the user edits the profile they are set.
        $this->RefreshProfileSubscriptions($user);
    }

    function beforeProfileDisplay(&$user, $dontcare, $cbUserIsModerator, $cbMyIsModerator)
    {
        // Refresh the profile just before displaying it.
        $this->RefreshProfileSubscriptions($user);
    }

    /*
     * This function mainly just checks to see if the user changed his/her email address(es) or profile fields.
     * If so, all existing subscription records matching the old email address are modified to have the new email
     * address and the profile field values are replaced even if now null. This function does not subscribe any
     * new addresses to any mailing lists (see afterUserUpdate).
     */
    function beforeUserUpdate(&$user, &$cbUser)
    {
        // Check for duplicate email addresses
        if ($this->anyDuplicateAddresses($user, $cbUser)) return false;

        // Let's get the database
        static $db;
        $db = JFactory::getDBO();
   
        // Point to plugin parameters
        $params = $this->params;

        // Using Mailster groups for account emails, Mailster users for extra emails.
        $lists = $this->getLists();

        // Prepare to notify the user
        $message = "";
        $changes = 0;

        // Get all old and new email addresses for this user's account.
        $old_info = $this->getEmails($user, $dontcare, $dontcare, FETCH);
        $new_info = $this->getEmails($user, $dontcare, $dontcare, DONT_FETCH);

        // If any of the user's email addresses or associatetd names changed, modify all matching subscription records.
        $email = count($old_info);
        foreach ($old_info as $old)
        {
            /*
             * Get the new_info email and name corresponding to this old_info
             */
            $new = $new_info[$email];

            /*
             * We're checking for a change in email address or associated name, not a change in
             * subscribership. Of course, if an email address that is subscribed to a Mailster list is
             * modified, it will be modified for every list to which it was subscribed.
             */
            if ((strtolower($new->email) != strtolower($old->email)) ||
                           ($new->name   !=            $old->name))
            {
                // Loop through all the lists. Need to do this to check for existing subscriptions.
                foreach ($lists as $list)
                {
                    // See if there is a record like the one we are about to create.
                    $db->setQuery("SELECT COUNT(*) FROM #__mailster_users u JOIN #__mailster_group_users g ON g.user_id=u.id WHERE u.email='$old->email' AND g.group_id='$list->group_id';");
                    $subscriptions = $db->loadResult();
                    if ($subscriptions > 0)
                    {
                        // We need to update the email address for the existing subscription.
                        $this->UpdateSubscribedEmail ($old->email, strtolower($new->email), ucwords(strtolower($new->name)));
                        if ($new->email != $old->email)
                        {
//                            $this->UnsubscribeAddress ($old->email, $list);
                        }
                    }
                }
                $changes++;
                $message .= str_replace(array('[LIST]',    '[OLD]',   '[EMAIL]', '[LABEL]',      '[FIELD]'),
                                        array($list->name,$old->email,$new->email,$old->label,$old->field),
                                        $params->get('changed_email_msg',DEFAULT_ADDRESS_CHANGE_MSG));
            }
            $email--;
        }
        // Send notification of email address changes, if any.
        if ($changes > 0)
        {
            $result = $this->NotifyUser($user, $message);
        }
        return true;
    }

    /*
     * Checks all profile list subscription selections and compares them against
     * existing subscriptions.
     */
    function afterUserUpdate ($user, $cbUser, $something=true)
    {
        // Initialize email message
        $message = "";
        
        // Update the subscriptions for this user
        $number = $this->UpdateSubscriptions($user, $message);

        // If applicable, notify the user of the subscription changes.
        if ($number) $result = $this->NotifyUser($user, $message);

        return true;
    }

    /*
     * REGISTRATION SEQUENCE:
     *  1. User registers - If neither confirmation nor approval is required, user can be processed.
     *  2. If confirmation is required, this happens next. - If approval is not required, user can be processed.
     *  3. If admin approval is required, this happens next. - If confirmation is not required, user can be processed.
     */

    /*
     * Handles user registration email address entries, mainly checking for duplicates.
     */
    function beforeUserRegisters ($user, $cbUser, $something=false)
    {
        // Check for duplicate email addresses
        if ($this->anyDuplicateAddresses($user, $cbUser)) return false;
        return true;
    }

    /*
     * Handles user activation, which is the event that completes the user object store after a new user is approved.
     */
    function afterUserActivated (&$user, $ui, $cause, $mailToAdmins, $mailToUser)
    {
        $result = $this->afterNewUser($user, $user);
        return true;
    }
    
    /*
     * Subscribes the new user to all email lists.
     */
    function afterNewUser($user, $cbUser, $stored = false, $something = true)
    {
        // Let's get the database
        static $db;
        $db = JFactory::getDBO();
   
        // Point to plugin parameters
        $params = $this->params;

        // Get lists to auto-subscribe and notification email format
        $auto = str_replace(" ","",$params->get('autosubscribe','')).",";  // remove spaces from auto list
        $format = intval($params->get('email_format','0'));

        // Fetch list of lists.
        $lists = $this->getLists();
        
        // Initialize email message
        $message = "";
        
        // Subscribe new user's account email to all auto-subscribe lists.
        $number = 0;
        foreach ($lists as $list)
        {
            if ($auto == "*," || strstr($auto,$list->group_id.","))
            {
                /* Add the subscription */
                $result = $this->SubscribeAddress($user->email, $list->group_id, $user->name);
                $message .= str_replace(array('[LIST]','[EMAIL]'),array($list->value,$user->email),$params->get('autosubscribe_email_msg',DEFAULT_AUTOSUBSCRIBE_MSG));
                $number++;
            }
        }
        // Based on the auto-subscriptions, update the profile fields.
        $result = $this->RefreshProfileSubscriptions($user);

        // If applicable, notify the user of the subscription changes.
        if ($number) $this->NotifyUser($user, $message);

        return true;
    }

    /*
     * Remove all records from the subscriptions that contain
     * any email address belonging to this user.
     */
    function beforeDeleteUser($user, $store = true)
    {
        // Let's get the database
        static $db;
        $db = JFactory::getDBO();
   
        // Point to plugin parameters
        $params = $this->params;

        // See if we should even be doing this
        if ($params->get('send_termination_notice',"No") == "No") return false;
        
        // Get a complete list of all extended email addresses
        $addresslist = $fieldsquery = "";
        $addresses = $this->getEmails($user, $addresslist, $fieldsquery);

        // Unsubscribe all of this user's extended email addresses from all lists.
        foreach ($addresses as $address)
        {
            if ($address->email)
            {
                // Unsubscribe this address from all lists
                $this->UnsubscribeAddress($address->email);
                $message .= str_replace(array('[LIST]','[EMAIL]'),array(ALL_EMAIL_LISTS,$address->email),$params->get('unsubscribe_email_msg',DEFAULT_UNSUBSCRIBE_MSG));
            }
        }

        // If user is approved, notify that all addresses were unsubscribed from all lists
        if ($user->approved == 1)
        {
            if ($message) $this->NotifyUser($user, $message);
        }
        
	return true;
    }

    /******* UTILITY FUNCTIONS *******/
    /*
     * Fetches the states of the actual settings in the Mailster tables.
     * Overrides what may currently be set in #__comprofiler. This avoids having
     * to initially populate the CB fields associated with the email lists.
     * View profile events - ui=1 front, ui=2 backend
     */
    function RefreshProfileSubscriptions($user)
    {
        // Let's get the database
        static $db;
        $db = JFactory::getDBO();

        // Using Mailster groups for account emails, Mailster users for extra emails.
        $lists = $this->getLists();
        foreach ($lists as $list)
        {
            $selections[$list->name] = "";
        }

        // Get all email addresses for this user's account
        $addresses = $this->getEmails($user, $user_emails, $dontcare);

        // Fetch complete list of subscriptions from Mailster Group Users table for extra email addresses.
        $subscriptions = $this->getSubscriptions($user_emails);

        // Point to plugin parameters
        $params = $this->params;

        // Assess which lists the user is subscribed to.
        foreach ($lists as $list)
        {
            // If an options field name is specified, use that as the source for options for all email lists.
            // Otherwise, get the list email field name.
            // In either case, find the associated option list.
            $lists_field = $params->get('email_opts',"");
            if ($lists_field == "")
            {
                $optionsfield = $this->listDbFieldName($list->name);
            }
            else
            {
                $optionsfield = $lists_field;
            }

            // Set the CB field value for each list based on the actual subscription.
            $db->setQuery("SELECT * FROM #__comprofiler_fields WHERE name='$optionsfield';");
            $field = $db->loadObject();
            $listselections = "";
            if ($field->type == 'checkbox')
            {
                foreach ($subscriptions as $subscription)
                {
                    if ($subscription->group_id == $list->group_id)
                    {
                        if (strlen($selections[$list->name]) > 0) $selections[$list->name] .= "|*|";
                        $selections[$list->name] .= $fieldtitle;
                        break;
                    }
                }
                $listselections = $selections[$list->name];
            }
            elseif ($field->type == 'multicheckbox' || $field->type == 'codemulticheckbox' || $field->type == 'querymulticheckbox' ||
                    $field->type == 'multiselect'   || $field->type == 'codemultiselect'   || $field->type == 'querymultiselect')
            {
                /*
                 * Whatever the user had chosen before will be overridden by what the
                 * mailster_group_users table says and how that maps to the addresses
                 * entered by this user and supported by the site.
                 */
                // this is for the extra email addresses
                foreach ($subscriptions as $subscription)
                {
                    if ($subscription->group_id && ($subscription->group_id == $list->group_id))
                    {
                        foreach ($addresses as $address)
                        {
                            if ($subscription->email && (strtolower($subscription->email) == strtolower($address->email)))
                            {
                                // Get the title of the option associated with this email address
                                $db->setQuery("SELECT fieldtitle FROM #__comprofiler_field_values WHERE fieldid=".$field->fieldid." AND ordering=".$address->index.";");
                                $fieldtitle = $db->loadResult();

                                // See if checkbox is checked
                                if((stripos($selections[$list->name],$fieldtitle) === false))
                                {
                                    if (strlen($selections[$list->name]) > 0) $selections[$list->name] .= "|*|";
                                    $selections[$list->name] .= $fieldtitle;
                                }
                            }
                        }
                    }
                }
                $listselections = $selections[$list->name];
            }
            // Update the profile field with all subscribed addresses.
            $listfield = $this->listDbFieldName($list->name);
            $db->setQuery("UPDATE #__comprofiler SET $listfield = '$listselections' WHERE user_id=$user->id;");
            $db->execute();
        }
    }
    
    /*
     * Checks for duplicate email addresses entered by the current user, both in the
     * entry form and also in the database for all addresses in $addresses. Raises an
     * error and returns true if any dupes found, false otherwise (no error raised).
     * Note that if any duplicates are found at this point, all user edits just made
     * are discarded. To be effective, must be called prior to committing entries to
     * the database (i.e., from the "onBefore" handlers).
     */
    function anyDuplicateAddresses($user, $cbUser)
    {
        // Let's get the database
        static $db;
        global $_PLUGINS;
        $db = JFactory::getDBO();

        // Point to plugin parameters
        $params = $this->params;

        // Get a list of CB fields to fetch email addresses from, if any
        $emails = intval($params->get('emails',"0"));
        $user_emails = "'EMAILADDRESS'";
        $addresses = array();
        for ($email = 1; $email <= $emails; $email++)
        {
            $field = $params->get("email$email","");
            if (strlen(trim($field)) > 0)
            {
                $addresses["$email"] = new userEmailObj();
                $addresses["$email"]->field = $field;
                if ($cbUser->$field)
                    $fieldvalue = $cbUser->$field;
                else
                    $fieldvalue = $user->$field;
                if (strlen(trim($fieldvalue)) > 0)
                {
                    $addresses["$email"]->email = $fieldvalue;
                    $user_emails .= ",'".$fieldvalue."'";
                }
            }
        }

        // Ensure all email addresses are unique and unique to this user
        $in_other_emails = "";
        foreach ($addresses as $address)
        {
            if ($address->email)
            {
                // Error if any email address was entered more than once
                if (substr_count(strtolower($user_emails),"'".strtolower($address->email)."'") > 1)
                {
                    $_PLUGINS->raiseError(0);
                    $_PLUGINS->_setErrorMSG(DUPLICATE_ADDRESS_ENTERED.' ('.$address->email.')<br>'.CHANGES_NOT_SAVED);
                    return true;
                }
                elseif ($address->field) $in_other_emails .= " OR (LOWER(".$address->field.") IN (".strtolower($user_emails)."))";
            }
        }
        $query = "SELECT * FROM #__users AS u".
                 " INNER JOIN #__comprofiler AS c ON u.id=c.id".
                 " WHERE (u.id <> ".$user->id.")".
                 " AND ( (LOWER(email) IN (".strtolower($user_emails).")) $in_other_emails );";
        $db->setQuery($query);

        // If any records are returned from the above query, at least one email address is not unique
        $duperecords = $db->loadObjectList();
        if (count($duperecords))
        {
            $_PLUGINS->raiseError(0);
            $_PLUGINS->_setErrorMSG(ADDRESS_ALREADY_IN_USE.' ('.$address->email.')<br>'.CHANGES_NOT_SAVED);
            return true;
        }
        // no dupes found
        return false;
    }

    /*
     * Subscribes an address to the list. This includes adding Mailster table records
     * but NOT setting the corresponding profile flag in #__comprofiler.
     */
    function SubscribeAddress($email, $group_id, $name)
    {
        // Let's get the database
        static $db;
        $db = JFactory::getDBO();

        // We need to know where this email address is coming from or if it exists at all yet.
        $is_joomla_user = 0;
        $db->setQuery("SELECT * FROM #__users WHERE email='$email';");
        $user = $db->loadObject();
        
        if ($user)
        {
            $is_joomla_user = 1;
        }
        else
        {
            $db->setQuery("SELECT * FROM #__mailster_users WHERE email='$email';");
            $user = $db->loadObject();
        }
        
        if ($user)
        {
            $user_id = $user->id;
        }
        else
        {
            // This email address doesn't exist yet. Add it to the Mailster users list.
            $db->setQuery("INSERT INTO #__mailster_users (email,name) VALUES ('$email','$name');");
            $db->execute();
            $db->setQuery("SELECT id FROM #__mailster_users WHERE email='$email';");
            $user_id = $db->loadResult();
        }
        // Subscribe the address to the list.
        $db->setQuery("INSERT INTO #__mailster_group_users (group_id,user_id,is_joomla_user) VALUES ($group_id,$user_id,$is_joomla_user);");
        $db->execute();
        return true;
    }
    
    /*
     * Unsubscribes an address from one or all lists. This includes deleting any user records
     * (if any) but NOT clearing the corresponding profile flag in #__comprofiler.
     * If list is not provided, the address is unsubscribed from all lists.
     */
    function UnsubscribeAddress($email, $list=NULL)
    {
        // Let's get the database
        static $db;
        $db = JFactory::getDBO();
        $result = false;

        // It's going to be in one place or the other, but not both.
        // And there will only be one record matching in either case.
        $db->setQuery("SELECT * FROM #__users WHERE email='$email';");
        $user = $db->loadObject();
        if (!$user)
        {
            $db->setQuery("SELECT * FROM #__mailster_users WHERE email='$email';");
            $user = $db->loadObject();
        }

        // Nothing to do if we couldn't find a user with that email address (unlikely).
        if ($user)
        {
            // Create the list query.
            if ($list)
            {
                $listquery = "AND group_id='$list->group_id'";
            }
            else
            {
                $listquery = "";
            }
            // Unsubscribe the user from the list.
            $db->setQuery("DELETE FROM #__mailster_group_users WHERE user_id=$user->id $listquery;");
            $db->execute();
            $result = true;

            // Check to see if there are any other subscriptions for this address.
            $db->setQuery("SELECT COUNT(*) FROM #__mailster_group_users WHERE user_id=$user->id;");
            if ($db->loadResult() == 0)
            {
                // No more subscriptions, so remove the address completely.
                $db->setQuery("DELETE FROM #__mailster_users WHERE id=$user->id;");
                $db->execute();
            }
        }
    }

    /*
     * Fetches all email addresses and names associated with the current user and returns them in an array.
     * Also returns a database query fragment for selecting email addresses from the subscription table.
     * Use of $fieldsquery requires a JOIN query between #__users and #__comprofiler based on id.
     */
    function getEmails($user, &$addresslist, &$fieldsquery, $fetch=FETCH)
    {
        // Let's get the database
        static $db;
        $db = JFactory::getDBO();

        // Point to plugin parameters
        $params = $this->params;

        // Setup some default lists and strings
        $emails = array();
        $addresslist = "'EMAILADDRESS'"; 
        $fieldsquery = "";

        // Get this user's entire record from the database if requested. Note this overwrites the $user object passed in.
        if($fetch == FETCH)
        {
            $db->setQuery("SELECT * FROM #__users as u INNER JOIN #__comprofiler as c ON u.id=c.id WHERE u.id=".$user->id.";");
            $user = $db->loadObject();
        }

        // Get a list of CB fields to fetch email addresses from, if any
        $Nemails = intval($params->get('emails',"0"));
        if ($Nemails)
        {
            for ($emailN = 1; $emailN <= $Nemails; $emailN++)
            {
                $emailfield = trim($params->get('email'.$emailN,""));
                if (strlen($emailfield) > 0)
                {
                    // get the name associated with this address (maybe)
                    if ($emailfield == 'email')
                    {
                        // account email is easy
                        $emailname = $user->name;
                    }
                    else
                    {
                        // Extra emails require the fields in params exist. Apply naming convention.
                        $firstnamefield = $emailfield."firstname";
                        $lastnamefield  = $emailfield."lastname";

                        // See if firstname field exists
                        $db->setQuery("SELECT title FROM #__comprofiler_fields WHERE name='$firstnamefield';");
                        $firstnameexists = $db->loadResult();
                        
                        // See if lastname field exists
                        $db->setQuery("SELECT title FROM #__comprofiler_fields WHERE name='$lastnamefield';");
                        $lastnameexists = $db->loadResult();

                        // Concatenate first and last names. If they're blank, oh well.
                        $emailname = "";
                        if ($firstnameexists)
                        {
                            $emailname = trim($user->$firstnamefield);
                        }
                        if ($lastnameexists)
                        {
                            $emailname .= " ".trim($user->$lastnamefield);
                            $emailname = trim($emailname);
                        }
                    }
                    // Get the name of the email address field. This also tells us if it exists.
                    $db->setQuery("SELECT title FROM #__comprofiler_fields WHERE name='$emailfield';");
                    $emailfieldtitle = $db->loadResult();
                    
                    $emails[$emailN] = new userEmailObj();  // need this to keep inquiring code in sync
                    if ($emailfieldtitle)
                    {
                        $emails[$emailN]->label = $emailfieldtitle;
                        $emails[$emailN]->field = $emailfield;
                        $emails[$emailN]->email = $user->$emailfield;
                        $emails[$emailN]->index = $emailN;
                        $emails[$emailN]->name  = $emailname;
                        if (strlen($fieldsquery)) $fieldsquery .= ","; $fieldsquery .= "$emailfield";
                        if (strlen($addresslist)) $addresslist .= ","; $addresslist .= "'".$user->$emailfield."'";
                    }
                }
            }
        }
        else
        {
            // Only using the primary email address
            $db->setQuery("SELECT title FROM #__comprofiler_fields WHERE name='email';");
            $emailfieldtitle = $db->loadResult();
            $emails[1] = new userEmailObj();
            $emails[1]->label = $emailfieldtitle;
            $emails[1]->field = $fieldsquery = 'email';
            $emails[1]->email = $user->email;
            $emails[1]->index = 1;
            $emails[1]->name  = $user->name;
            $addresslist = "'".$user->email."'";
        }
        return array_reverse($emails,true);
    }

    /*
     * Separates all profile field:pairs associated with each email address and returns them in an array
     * indexed in the same order getEmails orders the email addresses.
     */
    function getProfileFields($user, &$addresslist, &$fieldsquery)
    {
        // Point to plugin parameters
        $params = $this->params;

        // Setup some default lists and strings
        $fieldpairs[] = array();

        // Get a list of CB fields to fetch email addresses from, if any
        $emails = intval($params->get('emails',"0"));
        if ($emails)
        {
            for ($email = 1; $email <= $emails; $email++)
            {
                $profilefields = $params->get('email'.$email."fields","");
                if (strlen(trim($profilefields)) > 0)
                {
                    // Separate list of field:pairs into a double-subscripted array
                    $thispair = explode(",",preg_replace("/\s+/","",$profilefields));
                    $fieldpairs[$email] = new stdClass();
                    $fieldpairs[$email]->firstname = $thispair[0];
                    $fieldpairs[$email]->lastname = $thispair[1];
                }
            }
        }
        else
        {
            // Only using the primary email address
            $fieldpairs[$email] = new stdClass();
            $fieldpairs[1]->firstname = "firstname";
            $fieldpairs[1]->lastname = "lastname";
        }
        return array_reverse($fieldpairs,true);
    }

    /*
     * The specified list must have a multicheckbox field associated with it.
     * Returns the names of the fields associated with the specified list in an array.
     * The array key is the field label, which makes it easy to lookup by exploding the
     * list multicheckbox. All options are returned and if the specified user is subscribed
     * to one, the subscribed attribute is set to TRUE.
     */
    function getOptionList($list,$user)
    {
        // Let's get the database
        static $db;
        $db = JFactory::getDBO();
    
        // Get a list of CB fields to fetch email addresses from, if any
        $params = $this->params;
        $Nemails = intval($params->get('emails',"0"));
        $emails[] = array();
        for ($email = 1; $email <= $Nemails; $email++)
        {
            $field = $params->get("email".$email,"");
            if (strlen(trim($field)) > 0)
            {
                $emails[$email] = new stdClass();
                $emails[$email] = $field;
            }
        }

        // If an options field name is specified, use that as the source for options for all email lists.
        // Otherwise, get the list email field name.
        // In either case, find the associated option list.
        $lists_field = $params->get('email_opts',"");
        if ($lists_field == "")
        {
            $opt_field_name = $this->listDbFieldName($list->name);
        }
        else
        {
            $opt_field_name = $lists_field;
        }

        /* Get a complete list of options for this list. Note that we don't really need
         * most of the stuff this query fetches multiple times, but doing it this way
         * requires only a single query, which is always nice.
         */ 
        $db->setQuery("SELECT v.* FROM #__comprofiler_field_values v".
                            " INNER JOIN #__comprofiler_fields f".
                            " ON v.fieldid=f.fieldid".
                            " WHERE f.name='".$opt_field_name."'".
                            " ORDER BY v.ordering;");
        $options = $db->loadObjectList();

        // Get the necessary information for this user
        $db->setQuery("SELECT * FROM #__users u INNER JOIN #__comprofiler c ON u.id=c.id WHERE u.id=".$user->id.";");
        $userstuff = $db->loadObject();

        /* Attributes in returned array are:
         *  array key - field title (label on the field...CB uses this to denote selection)
         *  field - name of database field
         *  email - email address user entered into this field (if any)
         *  ordering - order of field as defined in option list
         *  selected - true if user selected to subscribe this email address to the list
         */
        foreach ($options as $option)
        {
            $field = $emails[$option->ordering];
            $address[$option->fieldtitle] = new stdClass();
            $address[$option->fieldtitle]->field = $field;
            $address[$option->fieldtitle]->email = $userstuff->$field;
            $address[$option->fieldtitle]->ordering = $option->ordering;
            $address[$option->fieldtitle]->selected = 0;
        }
        // Get the selected options for the specified list for this user
        $field = $this->listDbFieldName($list->name);
        $selections = $userstuff->$field;

        // Separate selected options.
        if ($selections)
        {
            $selections = explode('|*|',$selections);
            foreach ($selections as $selection)
            {
                $address[$selection]->selected = true;
            }
        }
        return $address;
    }

    /*
     * Based on the choices the user or administrator made for list subscriptions, update
     * the Mailster subscriptions tables. Also, build a message to send to the user/admin as a
     * notification. If any changes in subscriptions occur during this function, a non-zero number
     * is returned and message will be non-zero length.
     */
    function UpdateSubscriptions ($user, &$message)
    {
        // Let's get the database
        static $db;
        $db = JFactory::getDBO();

        // Get back-end table names
        $params = $this->params;
        
        // Get email notice format
        $format = intval($params->get('email_format','0'));
        
        // Fetch list of lists.
        $lists = $this->getLists();

        // Get all email addresses for this user's account
        $addresses = $this->getEmails($user, $user_emails, $fieldsquery);

        // Assess which lists the user wishes to be un/subscribed from/to.
        $fieldsquery = $message = "";
        $number = 0;
        foreach ($lists as $list)
        {
            // Fetch list of subscriptions to this list.
            $subscriptions = $this->getSubscriptions($user_emails,$list->group_id);

            // Set the CB field value for each list based on the actual subscription.
            $listfield = $this->listDbFieldName($list->name);
            $db->setQuery("SELECT * FROM #__comprofiler_fields WHERE name='$listfield';");
            $field = $db->loadObject();
            if($field->type == 'checkbox')
            {
                // Assume not subscribed
                $subscribed = 0;
                foreach ($subscriptions as $subscription)
                {
                    if ($subscription->group_id == $list->group_id)
                    {
                        // A subscription to this list already exists
                        $subscribed = 0;
                        break;
                    }
                }
                // Unsubscribe if subscribed and not checked, and vice versa.
                $db->setQuery("SELECT ".$this->listDbFieldName($list->name)." FROM #__comprofiler WHERE id=".$user->id.";");
                $boxchecked = $db->loadResult();
            
                if ($subscribed && !$boxchecked)
                {
                    $this->UnsubscribeAddress($user->email, $list);
                    $message .= $params->get('unsubscribe_email_msg',DEFAULT_UNSUBSCRIBE_MSG);
                    $number++;
                }
                elseif ($boxchecked && !$subscribed)
                {
                    if ($this->SubscribeAddress($user->email, $list->group_id, $user->name))
                    {
                        $message .= $params->get('subscribe_email_msg',DEFAULT_SUBSCRIBE_MSG);
                        $number++;
                    }
                }
                // Replace notice message placeholders
                $message = str_replace(array('[LIST]','[EMAIL]'),array($list->value,$user->email),$message);
            }
            elseif ($field->type == 'multicheckbox' || $field->type == 'codemulticheckbox' || $field->type == 'querymulticheckbox' ||
                    $field->type == 'multiselect'   || $field->type == 'codemultiselect'   || $field->type == 'querymultiselect')
            {
                // Get a list of which address(es) this user has subscribed to this list
                $options = $this->getOptionList($list, $user);

                // Check each address to see if it is subscribed
                foreach ($options as $option)
                {
                    // No sense checking for a subscription if there's no email adddress defined
                    if (strlen(trim($option->email)) > 0)
                    {
                        // Assume not subscribed
                        $subscribed = 0;
                        foreach ($subscriptions as $subscription)
                        {
                            if ($subscription->email == $option->email)
                            {
                                // A subscription to this list exists
                                $subscribed = 1;
                                break;
                            }
                        }
                        // Unsubscribe if subscribed and not checked, and vice versa.
                        if ($subscribed && !$option->selected)
                        {
                            $this->UnsubscribeAddress($option->email, $list);
                            $message .= $params->get('unsubscribe_email_msg',DEFAULT_UNSUBSCRIBE_MSG);
                            $number++;
                        }
                        elseif (!$subscribed && $option->selected && trim($option->email) != '')
                        {
                            if ($this->SubscribeAddress($option->email, $list->group_id, $user->name))
                            {
                                $message .= $params->get('subscribe_email_msg',DEFAULT_SUBSCRIBE_MSG);
                                $number++;
                            }
                        }
                        // Replace notice message placeholders. We have to replace [EMAIL] now
                        // since the extended addresses are not handled below.
                        $message = str_replace(array('[LIST]','[EMAIL]'),array($list->name,$option->email),$message);
                    }
                }
            }
        }
        return $number;
    }
    
    /*
     * Notify the user of any subscription changes. Note: this function should not be called
     * unless there is substance to the message.
     */
    function NotifyUser($user, $message, $subject='')
    {
        // Create application object
        $config = JFactory::getConfig();

        // Let's get the database
        static $db;
        $db = JFactory::getDBO();
   
        // Setup a mailer
        $mailer = JFactory::getMailer();
 
        // If applicable, notify the user of the subscription changes.
        $params = $this->params;
        $notify = $params->get('send_email_notice','No');
        if ($notify != "No")
        {
            // Get message prefix and suffix
            $prefix = $params->get('email_prefix','');
            $suffix = $params->get('email_suffix','');
        
            // Get email addresses
            $admin_addr = $params->get('admin_addr'     ,$config->get('mailfrom'));
            $from_name  = $params->get('email_from_name',$config->get('fromname'));
            $from_addr  = $params->get('email_from_addr',$admin_addr);

            // Replace notice message placeholders
            if (strlen($subject) == 0)
            {
                $subject = $params->get('email_subject',DEFAULT_EMAIL_SUBJECT);
            }
            $subject = str_replace(array('[SITE]','[EMAIL]','[USER]'),array($config->get('sitename'),$user->email,$user->name),$subject);
            $message = str_replace(array('[SITE]','[EMAIL]','[USER]'),array($config->get('sitename'),$user->email,$user->name),$prefix.$message.$suffix);

            // Get email format
            $format = intval($params->get('email_format','0'));

            // Who gets this notification?
            $recipient = array();
            $copyto = array();
            $bcc = array();
            $recipient = "";
            $copyto = "";
            $bcc = "";
            switch ($notify)
            {
                case "Admin": // admin only
                    $recipient = preg_split("/[\s,]+/",$admin_addr);
                    break;
                case "User": // user only
                    $recipient = $user->email;
                    break;
                case "Both": // user and admin
                default:
                    $recipient = $user->email;
                    $bcc = preg_split("/[\s,]+/",$admin_addr);
                break;
            }
            
            // Build e-mail message format and send email.
            $mailer->setSender(array($from_addr, $from_name));
            $mailer->setSubject($subject);
            $mailer->setBody($message);
            $mailer->addRecipient($recipient);
            if ($copyto) $mailer->addCC($copyto);
            if ($bcc)    $mailer->addBCC($bcc);
            $mailer->IsHTML($format);
            $mailer->Send();
        }
    }

    /*
     * The name of the list in the database cannot have any whitespace.
     */
    function listDbFieldName($name)
    {
        return "cb_".strtolower(str_replace("\s+","_",$name));
    }

    /*
     * Replace the old email with the new one in the user list.
     * This will do nothing for account email addresses.
     */
    function UpdateSubscribedEmail ($old_email, $new_email, $new_name)
    {
        static $db;
        $db = JFactory::getDBO();

        $db->setQuery("UPDATE #__mailster_users SET email='$new_email', name='$new_name' WHERE email='$old_email';");
        $db->execute();
    }

    /*
     * Return a list of subscription objects, optionally for a specific list.
     * The $user_emails parameter must be of the form "'email1@domain.com','email2@domain.com'"
     * as provided by function getEmails.
     */
    function getSubscriptions($user_emails,$group_id=0)
    {
        static $db;
        $db = JFactory::getDBO();

        // See if we just need subscriptions for a specific list.
        if ($group_id > 0)
        {
            $listquery = " AND group_id=$group_id";
        }
        else
        {
            $listquery = "";
        }
        // Fetch list of subscriptions from Mailster Group Users table for extra email addresses.
        $query = "SELECT * FROM (SELECT DISTINCT user_id,group_id,if(isnull(m.name),u.name,m.name) as name,if(isnull(m.email),u.email,m.email) as email FROM #__mailster_group_users g LEFT JOIN #__mailster_users m ON g.user_id=m.id LEFT JOIN #__users as u ON g.user_id=u.id) AS everyone WHERE email IN (".$user_emails.")".$listquery." ORDER BY user_id;";
        $db->setQuery($query);
        return $db->loadObjectList();
    }

    /*
     * Returns a list of email lists (objects).
     */
    function getLists()
    {
        static $db;
        $db = JFactory::getDBO();

        // Fetch list of lists.
        $db->setQuery("SELECT list_id,group_id,g.name FROM #__mailster_groups g JOIN #__mailster_lists l JOIN #__mailster_list_groups lg ON lg.list_id=l.id AND lg.group_id=g.id WHERE g.name NOT LIKE '% %';");
        return $db->loadObjectList();
    }
}
?>