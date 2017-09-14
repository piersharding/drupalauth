<?php

/**
 * @file
 * The UserPass authentication source.
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$globalConfig = SimpleSAML_Configuration::getInstance();
if (!$globalConfig->getString('drupal.root', false)) {
    throw new SimpleSAML_Error_Exception('drupal.root must be set in config.php.');
}

global $drupal_autoloader;
$drupal_autoloader = include_once $globalConfig->getString('drupal.root') . '/web/autoload.php';

/**
 * Drupal authentication source for simpleSAMLphp.
 *
 * Copyright SIL International, Steve Moitozo, <steve_moitozo@sil.org>, http://www.sil.org.
 *
 * This class is a Drupal authentication source which authenticates users
 * against a Drupal site located on the same server.
 *
 *
 * The homepage of this project: http://code.google.com/p/drupalauth/
 *
 * !!! NOTE WELLL !!!
 *
 * You must configure store.type in config/config.php to be something
 * other than phpsession, or this module will not work. SQL and memcache
 * work just fine. The tell tail sign of the problem is infinite browser
 * redirection when the SimpleSAMLphp login page should be presented.
 *
 * -------------------------------------------------------------------
 *
 * To use this put something like this into config/authsources.php:
 *
 *  'drupal-userpass' => array(
 *      'drupalauth:UserPass',
 *
 *      // The filesystem path of the Drupal directory.
 *      'drupalroot' => '/var/www/drupal-7.0',
 *
 *      // Whether to turn on debug
 *      'debug' => true,
 *
 *      // Which attributes should be retrieved from the Drupal site.
 *
 *              'attributes' => array(
 *                                    array('drupaluservar'   => 'uid',  'callit' => 'uid'),
 *                                     array('drupaluservar' => 'name', 'callit' => 'cn'),
 *                                     array('drupaluservar' => 'mail', 'callit' => 'mail'),
 *                                     array('drupaluservar' => 'field_first_name',  'callit' => 'givenName'),
 *                                     array('drupaluservar' => 'field_last_name',   'callit' => 'sn'),
 *                                     array('drupaluservar' => 'field_organization','callit' => 'ou'),
 *                                     array('drupaluservar' => 'roles','callit' => 'roles'),
 *                                   ),
 *  ),
 *
 * Format of the 'attributes' array explained:
 *
 * 'attributes' can be an associate array of attribute names, or NULL, in which case
 * all attributes are fetched.
 *
 * If you want everything (except) the password hash do this:
 *      'attributes' => NULL,
 *
 * If you want to pick and choose do it like this:
 * 'attributes' => array(
 *            array('drupaluservar' => 'uid',  'callit' => 'uid),
 *                     array('drupaluservar' => 'name', 'callit' => 'cn'),
 *                     array('drupaluservar' => 'mail', 'callit' => 'mail'),
 *                     array('drupaluservar' => 'roles','callit' => 'roles'),
 *                      ),
 *
 *  The value for 'drupaluservar' is the variable name for the attribute in the
 *  Drupal user object.
 *
 *  The value for 'callit' is the name you want the attribute to have when it's
 *  returned after authentication. You can use the same value in both or you can
 *  customize by putting something different in for 'callit'. For an example,
 *  look at the entry for name above.
 *
 * @author  Steve Moitozo <steve_moitozo@sil.org>, SIL International
 * @package drupalauth
 * @version $Id$
 */
class sspmod_drupalauth_Auth_Source_UserPass extends sspmod_core_Auth_UserPassBase
{

    // Whether to turn on debugging.
    private $debug;

    // The Drupal installation directory.
    private $drupalroot;

    // The Drupal user attributes to use, NULL means use all available.
    private $attributes;

    /**
     * Constructor for this authentication source.
     *
     * @param array $info
     *   Information about this authentication source.
     * @param array $config
     *   Configuration.
     */
    public function __construct($info, $config)
    {
        global $drupal_autoloader;
        assert('is_array($info)');
        assert('is_array($config)');

        /* Call the parent constructor first, as required by the interface. */
        parent::__construct($info, $config);

        /* Get the configuration for this module */
        $drupalAuthConfig = new sspmod_drupalauth_ConfigHelper(
            $config,
            'Authentication source ' . var_export($this->authId, true)
        );

        $this->debug      = $drupalAuthConfig->getDebug();
        $this->attributes = $drupalAuthConfig->getAttributes();

        // Bootstrap Drupal to different levels.
        try {
            $request = Request::createFromGlobals();
            $kernel = DrupalKernel::createFromRequest($request, $drupal_autoloader, 'prod');
            $kernel->boot();
            $kernel->prepareLegacyRequest($request);
            $this->em = $kernel->getContainer()->get('entity.manager');
            // Drupal's bootstrap overrides SimpleSamlPHP's exception handler.
            restore_exception_handler();
        }
        catch (Exception $e) {
            throw new Exception('Drupal bootstrap failed: ' . $e->getMessage());
        }
    }

    /**
     * Attempt to log in using the given username and password.
     *
     * On a successful login, this function should return the users attributes. On failure,
     * it should throw an exception. If the error was caused by the user entering the wrong
     * username or password, a SimpleSAML_Error_Error('WRONGUSERPASS') should be thrown.
     *
     * Note that both the username and the password are UTF-8 encoded.
     *
     * @param string $username
     *   The username the user wrote.
     * @param string $password
     *   The password the user wrote.
     *
     * @return array  Associative array with the users attributes.
     */
    protected function login($username, $password)
    {
        assert('is_string($username)');
        assert('is_string($password)');

        // Authenticate the user.
        try {
            $drupaluid = \Drupal::service('user.auth')->authenticate($username, $password);
        }
        catch (Exception $e) {
            throw new Exception('Drupal service user.auth failed: ' . $e->getMessage());
        }

        if (0 == $drupaluid) {
            throw new SimpleSAML_Error_Error('WRONGUSERPASS');
        }

        // Load the user object from Drupal.
        try {
            $accounts = $this->em->getStorage('user')->loadByProperties(array('name' => $username, 'status' => 1));
            $drupaluser = reset($accounts);
        }
        catch (Exception $e) {
            throw new Exception('Drupal user loadByProperties failed: ' . $e->getMessage());
        }
        if (!$drupaluser || $drupaluser->isBlocked()) {
            // User has been blocked.
            throw new SimpleSAML_Error_Error('WRONGUSERPASS');
        }

        // Get all the attributes out of the user object.
        $userAttrs = array(
            'uuid' => $drupaluser->uuid(),
            'id' => $drupaluser->id(),
            'username' => $drupaluser->getAccountName(),
            'email' => $drupaluser->getEmail(),
            'displayname' => $drupaluser->getDisplayName(),
            'roles' => $drupaluser->getRoles(),
            // 'language' => $drupaluser->language()->id(),
            'timezone' => $drupaluser->getTimeZone(),
        );

        foreach ($drupaluser->toArray() as $key => $value) {
            if (preg_match('/^field_/', $key)) {
                $userAttrs[$key] = $this->loadDrupalField($drupaluser, $key);
            }
        }

        // Define some variables to use as arrays.
        $userAttrNames = null;
        $attributes    = null;

        // Figure out which attributes to include.
        if (null == $this->attributes) {
            $userKeys = array_keys($userAttrs);

            // Populate the attribute naming array.
            foreach ($userKeys as $userKey) {
                $userAttrNames[$userKey] = $userKey;
            }

        }
        else {
            // Populate the array of attribute keys and
            // populate the attribute naming array.
            foreach ($this->attributes as $confAttr) {

                $userKeys[] = $confAttr['drupaluservar'];
                $userAttrNames[$confAttr['drupaluservar']] = $confAttr['callit'];

            }

        }

        // An array of the keys that should never be included
        // (e.g., pass)
        $skipKeys = array('pass');

        // Package up the user attributes.
        foreach ($userKeys as $userKey) {

            // Skip any keys that should never be included.
            if (!in_array($userKey, $skipKeys)) {

                if (is_string($userAttrs[$userKey])
                    || is_numeric($userAttrs[$userKey])
                    || is_bool($userAttrs[$userKey])
                ) {

                    $attributes[$userAttrNames[$userKey]] = array($userAttrs[$userKey]);

                }
                elseif (is_array($userAttrs[$userKey])) {

                    // If the field is a field module field, special handling is required.
                    if (substr($userKey, 0, 6) == 'field_' && isset($userAttrs[$userKey]['und'])) {
                        $attributes[$userAttrNames[$userKey]] = array($userAttrs[$userKey]['und'][0]['safe_value']);
                    }
                    else {
                        // Otherwise treat it like a normal array.
                        $attributes[$userAttrNames[$userKey]] = $userAttrs[$userKey];
                    }
                }
            }
        }
        return $attributes;
    }

    /**
     * Load the values for a drupal field.
     *
     * This function runs recursively for paragraphs.
     *
     * @param object $object
     *   The object containing the field.
     * @param string $field
     *   The field machine name.
     * @return mixed
     *   The field value.
     */
    protected function loadDrupalField($object, $field)
    {
        // Check the field type.
        $field_type = $object->getFieldDefinition($field)->getType();
        $field_content = $object->get($field);
        $out = '';

        // Check if empty.
        if (!$field_content->isEmpty()) {
            switch ($field_type) {
            case 'string':
                $values = [];
                foreach ($field_content as $item) {
                    $values[] = $item->getValue()['value'];
                }
                $out = implode(', ', $values);
                break;

            case 'entity_reference_revisions':
                $out = [];
                foreach ($field_content as $item) {
                    $paragraph = \Drupal\paragraphs\Entity\Paragraph::load($item->getValue()['target_id']);
                    file_put_contents('/opt/debug.txt', print_r($paragraph->toArray(), true), FILE_APPEND);
                    file_put_contents('/opt/debug.txt', "\n\r", FILE_APPEND);
                    // Recursively get values for paragraph fields.
                    $paragraph_fields = [];
                    foreach ($paragraph->toArray() as $key => $value) {
                        if (preg_match('/^field_/', $key)) {
                            $paragraph_fields[] = $this->loadDrupalField($paragraph, $key);
                        }
                    }
                    $out[] = implode(' : ', $paragraph_fields);
                }
                break;

            case 'entity_reference':
                $out = [];
                foreach ($field_content as $item) {
                    $entity = $item->get('entity')->getTarget()->getValue();
                    if ($entity->hasField('title')) {
                        $title_content = $entity->get('title');
                        if (!$title_content->isEmpty()) {
                            $out = $title_content->first()->getValue()['value'];
                        }
                    }
                }
                break;
            }
        }
        return $out;
    }

}
