<?php
/*
 * Copyright (C) 2022 SYSTOPIA GmbH
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published by
 *  the Free Software Foundation in version 3.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Contact;
use Civi\Api4\Group;
use CRM_Birthdays_ExtensionUtil as E;

class CRM_Birthdays_BirthdayContacts
{
    private int $group_id;

    /**
     * @param int $custom_group_id Optional custom group ID to override default
     * @throws Exception
     */
    public function __construct(int $custom_group_id = 0)
    {
        try {
            if ($custom_group_id > 0) {
                // Validate that the custom group exists
                $this->group_id = $this->validateGroupId($custom_group_id);
            } else {
                // Use default birthday group
                $this->group_id = $this->getGroupIdFromApi();
            }
        } catch (Exception $exception) {
            throw new Exception(
                E::LONG_NAME . ' ' . E::ts(
                    'Group not found: %1',
                    [1 => $exception]
                )
            );
        }
    }

    /**
     * Validate that the given group ID exists
     * @param int $group_id
     * @return int
     * @throws Exception
     */
    private function validateGroupId(int $group_id): int
    {
        try {
            $group = Group::get()
                ->addSelect('id')
                ->addWhere('id', '=', $group_id)
                ->execute()
                ->single();
            return $group['id'];
        } catch (Exception $exception) {
            throw new Exception("Group with ID {$group_id} not found: {$exception}");
        }
    }

    /**
     * @throws Exception
     *
     * Debug mode allows you to send mails to a pre defined email.
     * For details see sendGreetings::_run
     */
    public function getBirthdayContactsOfToday($is_debug_email): array
    {
        try {
            if (!empty($is_debug_email)) {
                $limit = 'LIMIT 10';
                $day_filter = 'AND birth_date IS NOT NULL'; // just show up to 10 contacts no matter which birthdate
            } else {
                $limit = '';
                $day_filter = "AND DAY(birth_date) = DAY(CURDATE())
                              AND MONTH(birth_date) = MONTH(CURDATE())";
            }

            /*
             * Important:
             * Please sync documentation text here:
             * /templates/CRM/Birthdays/Form/Settings.tpl
             * which represents following url: /civicrm/admin/birthdays/settings
             * whenever this query changes
             */

            $sql = "SELECT civicrm_contact.id AS contact_id, 
                        civicrm_contact.birth_date AS birth_date,
                        civicrm_email.email AS email
                    FROM civicrm_contact
                        INNER JOIN civicrm_group_contact group_contact ON civicrm_contact.id = group_contact.contact_id
                        INNER JOIN civicrm_email ON civicrm_contact.id = civicrm_email.contact_id
                              WHERE civicrm_contact.contact_type = 'Individual'
                              {$day_filter}
                              AND civicrm_contact.is_opt_out = 0
                              AND civicrm_contact.do_not_email = 0
                              AND civicrm_contact.is_deceased = 0
                              AND group_contact.group_id = {$this->group_id}
                              AND civicrm_email.is_primary = 1 
                                  {$limit}";
            $query = CRM_Core_DAO::executeQuery($sql);
            $query_result = [];
            while ($query->fetch()) {
                $query_result[$query->contact_id] =
                    [
                        'birth_date' => $query->birth_date,
                        'email' => $is_debug_email ?: $query->email
                    ];
            }
            return $query_result;
        } catch (Exception  $exception) {
            throw new Exception(E::LONG_NAME . " " . "SQL query failed: $exception");
        }
    }

    /**
     * @throws Exception
     */
    private function getGroupIdFromApi(): int
    {
        $group_id = Group::get()
            ->addSelect('id')
            ->addWhere('name', '=', 'birthday_greeting_recipients_group')
            ->execute()
            ->single();
        return $group_id['id'];
    }

    /**
     * Check if the current group has contacts with birth dates
     * @throws UnauthorizedException
     * @throws CRM_Core_Exception
     * @throws Exception
     */
    public function groupHasBirthDateContacts(): bool
    {
        $contact_cont = Contact::get()
            ->addJoin('GroupContact AS group_contact', 'LEFT', ['group_contact.contact_id', '=', 'id'])
            ->addWhere('group_contact.group_id', '=', $this->group_id)
            ->addWhere('birth_date', 'IS NOT NULL')
            ->setLimit(1)
            ->execute()->count();

        return $contact_cont > 0;
    }
}
