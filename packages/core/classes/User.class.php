<?php
/*
    This file is part of the eQual framework <http://www.github.com/cedricfrancoys/equal>
    Some Rights Reserved, Cedric Francoys, 2010-2021
    Licensed under GNU GPL 3 license <http://www.gnu.org/licenses/>
*/
namespace core;

use equal\orm\Model;

class User extends Model {

    public static function constants() {
        return ['USER_ACCOUNT_DISPLAYNAME', 'USER_ACCOUNT_VALIDATION'];
    }

    public static function getName() {
        return 'User';
    }

    public static function getColumns() {
        return [
            'name' => [
                'type'              => 'computed',
                'result_type'       => 'string',
                'function'          => 'calcName',
                'store'             => true,
                'description'       => 'Display name used to refer to the user.',
                'help'              => 'Depending on the configuration, can be either the login, the first name, the fullname, or a nickname.'
            ],

            'login' => [
                'type'              => 'string',
                'usage'             => 'email',
                'required'          => true,
                'unique'            => true
            ],

            'username' => [
                'type'              => 'string',
                'unique'            => true
            ],

            'password' => [
                'type'              => 'string',
                'usage'             => 'password',
                'onupdate'          => 'onupdatePassword',
                'required'          => true
            ],

            'nickname' => [
                'type'              => 'alias',
                'alias'             => 'username'
            ],

            'firstname' => [
                'type'              => 'string'
            ],

            'lastname' => [
                'type'              => 'string'
            ],

            'fullname' => [
                'type'              => 'computed',
                'result_type'       => 'string',
                'function'          => 'calcFullname'
            ],

            // #todo - add a distinct field for 'locale'

            'language' => [
                'type'              => 'string',
                'usage'             => 'language/iso-639',
                'default'           => 'en',
                'description'       => "Preferred locale for user interfaces.",
            ],

            'validated' => [
                'type'              => 'boolean',
                'default'           => false,
                'description'       => 'Flag telling if the User has validated his email address.',
                'help'              => "This fields is used at signin to prevent non-validated user to log in."
            ],

            'groups_ids' => [
                'type'              => 'many2many',
                'foreign_object'    => 'core\Group',
                'foreign_field'     => 'users_ids',
                'rel_table'         => 'core_rel_group_user',
                'rel_foreign_key'   => 'group_id',
                'rel_local_key'     => 'user_id'
            ],

            'permissions_ids' => [
                'type'              => 'one2many',
                'foreign_object'    => 'core\Permission',
                'foreign_field'     => 'user_id'
            ],

            'setting_values_ids' => [
                'type'              => 'one2many',
                'foreign_object'    => 'core\setting\SettingValue',
                'foreign_field'     => 'user_id',
                'description'       => 'List of settings that relate to the user.'
            ],

            // application logic related status of the object
            'status' => [
                'type'              => 'string',
                // initial status
                'default'           => 'created'
                // list of possible statuses corresponds to the keys of the map returned by `getWorkflow()`
                // onupdate is allowed but it is better practice to rely on transitions and related function properties in the workflow descriptor
            ]
        ];
    }

    public static function getWorkflow() {
        return [
            'created' => [
                'transitions' => [
                    'validation' => [
                        'watch'       => ['validated'],
                        'domain'      => ['validated', '=', true],
                        'description' => 'Update the user status based on the `validated` field.',
                        'help'        => "The `validated` field is set by a dedicated controller that handles email confirmation requests.",
                        'status'	  => 'validated',
                        'onafter'     => 'onafterValidate'
                    ]
                ]
            ],
            'validated' => [
                'transitions' => [
                    'suspension' => [
                        'description' => 'Set the user status as suspended.',
                        'status'	  => 'suspended'
                    ],
                    'confirmation' => [
                        'domain'      => ['validated', '=', true],
                        'description' => 'Update the user status based on the `confirmed` field.',
                        'help'        => "The `confirmed` field is set by a dedicated controller that handles the account confirmation process (auto or manual).",
                        'status'	  => 'confirmed'
                    ]
                ]
            ],
            'confirmed' => [
                'transitions' => [
                    'suspension' => [
                        'description' => 'Set the user account as disabled (prevents signin).',
                        'status'	  => 'suspended'
                    ]
                ]
            ],
            'suspended' => [
                'transitions' => [
                    'confirmation' => [
                        'description' => 'Re-enable the user account.',
                        'status'	  => 'confirmed'
                    ]
                ]
            ]
        ];
    }

    public static function calcName($self) {
        $result = [];
        $mask = constant('USER_ACCOUNT_DISPLAYNAME');
        $self->read(['id', 'login', 'nickname', 'firstname', 'lastname', 'fullname']);
        foreach($self as $id => $user) {
            $parts = explode(' ', str_replace('-', ' ', $user['firstname'].' '.$user['lastname']));
            $initials = strtoupper(array_reduce($parts, function($c, $a) {return $c.substr($a, 0, 1);}, ''));
            $res = str_replace(['id', 'nickname', 'mail', 'givenname', 'surname', 'initials'], [$id, $user['nickname'], $user['login'], $user['firstname'], $user['lastname'], $initials], $mask);
            // fallback to user ID
            $result[$id] = (strlen($res) > 0)?$res:$id;
        }
        return $result;
    }

    /**
     * Filter method for password updates.
     * Make sure password is encrypted when stored to DB.
     * If not encrypted yet, password is hashed using CRYPT_BLOWFISH algorithm.
     * (This has to be done after password assign, in order to be able to validate the constraints set on password field.)
     *
     * @param   $om     Object  Instance of the ObjectManager Service
     * @param   $ids    array   List of User objects identifiers
     * @param   $lang   string  Language for multilang fields
     */
    public static function onupdatePassword($om, $ids, $values, $lang) {
        $values = $om->read(self::getType(), $ids, ['password']);
        foreach($values as $oid => $odata) {
            if(substr($odata['password'], 0, 4) != '$2y$') {
                $om->update(self::getType(), $oid, ['password' => password_hash($odata['password'], PASSWORD_BCRYPT)]);
            }
        }
    }

    /**
     * Compute the value of the fullname of the user (concatenate firstname and lastname).
     *
     * @param \equal\orm\Collection $self  An instance of a User collection.
     *
     */
    public static function calcFullname($self) {
        $result = [];
        $self->read(['firstname', 'lastname']);
        foreach($self as $id => $user) {
            $result[$id] = $user['firstname'].' '.$user['lastname'];
        }
        return $result;
    }

    /**
     * Handler run after successful transition to 'validated' status
     *
     */
    public static function onafterValidate($orm, $ids) {
        // no manual validation required : confirm
        if(!constant('USER_ACCOUNT_VALIDATION')) {
            return $orm->transition(self::getType(), $ids, 'confirmation');
        }
    }

    /**
     * Handler for single object values change in UI.
     * This method does not imply an actual update of the model, but a potential one (not made yet) and is intended for front-end only.
     *
     * @param  array            $event      Associative array holding changed fields as keys, and their related new values.
     * @param  array            $values     Copy of the current (partial) state of the object.
     * @return array            Returns an associative array mapping fields with their resulting values.
     */
    public static function onchange($event, $values) {
        $result = [];

        if(isset($event['firstname']) || isset($event['lastname'])) {

            if(isset($event['firstname'])) {
                if(isset($event['lastname'])) {
                    $result['fullname'] = $event['firstname'].' '.$event['lastname'];
                }
                else {
                    if(isset($values['lastname'])) {
                        $result['fullname'] = $event['firstname'].' '.$values['lastname'];
                    }
                    else {
                        $result['fullname'] = $event['firstname'];
                    }
                }
            }
            else {
                if(isset($values['firstname'])) {
                    $result['fullname'] = $values['firstname'].' '.$event['lastname'];
                }
                else {
                    $result['fullname'] = $event['lastname'];
                }
            }
        }

        return $result;
    }

    public static function getConstraints() {
        return [
            'username' =>  [
                'invalid_format' => [
                    'message'       => 'Username may only contain alphanumeric characters or single hyphens, and cannot begin or end with a hyphen.',
                    'function'      => function ($username, $values) {
                        return (bool) (preg_match('/^(?!-)[a-zA-Z0-9-]{1,20}(?<!-)$/u', $username));
                    }
                ]
            ]
        ];
    }

}