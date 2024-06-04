<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A scheduled task to process Microsoft group and Moodle cohort mapping.
 *
 * @package     local_o365
 * @copyright   Enovation Solutions Ltd. {@link https://enovation.ie}
 * @author      Patryk Mroczko <patryk.mroczko@enovation.ie>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_o365\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use local_o365\feature\cohortsync\main;

/**
 * A scheduled task to process Microsoft group and Moodle cohort mapping.
 */
class cohortsync extends scheduled_task {
    /**
     * Get the name of the task.
     *
     * @return string
     */
    public function get_name() : string {
        return get_string('cohortsync_taskname', 'local_o365');
    }

    /**
     * Execute the scheduled task.
     *
     * @return bool
     */
    public function execute() : bool {
        $graphclient = main::get_unified_api(__METHOD__);
        if (empty($graphclient)) {
            mtrace("... Failed to get Graph API client. Exiting.");

            return true;
        }

        $cohortsyncmain = new main($graphclient);
        $this->execute_sync($cohortsyncmain);

        return true;
    }

    /**
     * Execute synchronization.
     *
     * @param main $cohortsync
     * @return void
     */
    private function execute_sync(main $cohortsync) : void {
        if (!$cohortsync->update_groups_cache()) {
            mtrace("... Failed to update groups cache. Exiting.");

            return;
        }

        mtrace("... Start processing cohort mappings.");
        $grouplist = $cohortsync->get_grouplist();
        $grouplist = array_filter($grouplist, function($group) {
            return str_contains($group['displayName'], 'ochin-crowd-');
        });

        mtrace("...... Found " . count($grouplist) . " groups.");
        $grouplistbyoid = [];
        foreach ($grouplist as $group) {
            $grouplistbyoid[$group['id']] = $group;
        }

        $mappings = $cohortsync->get_mappings();
        $cohorts = $cohortsync->get_cohortlist();

        if (empty($mappings) || count($mappings) != count($grouplist)) {
            // Populate matches from existing cohorts if no mappings are found
            if(empty($mappings)) {
                mtrace("...... No mappings found. Creating matching to existing groups");
                
                foreach ($cohorts as $cohort) {
                    foreach ($grouplist as $group) {
                        if($group['displayName'] == $cohort->name) {
                            mtrace("Found matching group " . $cohort->name);

                            $added = $cohortsync->add_mapping($group['id'], $cohort->id);
                            if($added) mtrace(("Group mapped"));
                            else mtrace("Error mapping group");
                        }
                    }
                }
                
                return;
            }

            // Find groups to add
            $newgroups = $this->get_new_groups($cohorts, $grouplist);
            mtrace("Found " . count($newgroups) . " new groups");
            foreach($newgroups as $newgroup) {
                mtrace("Found new group " . $newgroup['displayName']);

                $new_cohort = new \stdClass();
                $new_cohort->name = $newgroup['displayName'];
                $new_cohort->contextid = 1;
                //$new_cohort->description = $newgroup->description;
                $id = cohort_add_cohort($new_cohort);

                mtrace("Added new group: " . $new_cohort->name . " with id: " . $id);

                $added = $cohortsync->add_mapping($newgroup['id'], $id);
                if($added) mtrace(("Group mapped"));
                else mtrace("Error mapping group");
            }

            // Delete groups
            //$removedgroups = $this->get_removed_groups($grouplist, $cohorts);
            //foreach($removedgroups as $removedgroup) {

            //}
        }

        mtrace("...... Found " . count($mappings) . " mappings.");

        foreach ($mappings as $key => $mapping) {
            // Verify that the group still exists.
            if (!in_array($mapping->objectid, array_keys($grouplistbyoid))) {
                $cohortsync->delete_mapping_by_group_oid_and_cohort_id($mapping->objectid, $mapping->moodleid);
                mtrace("......... Deleted mapping for non-existing group ID {$mapping->objectid}.");
                unset($mappings[$key]);
            }

            // Verify that the cohort still exists.
            if (!in_array($mapping->moodleid, array_keys($cohorts))) {
                $cohortsync->delete_mapping_by_group_oid_and_cohort_id($mapping->objectid, $mapping->moodleid);
                mtrace("......... Deleted mapping for non-existing cohort ID {$mapping->moodleid}.");
                unset($mappings[$key]);
            }
        }

        foreach ($mappings as $mapping) {
            mtrace("......... Processing mapping for group ID {$mapping->objectid} and cohort ID {$mapping->moodleid}.");
            $cohortsync->sync_members_by_group_oid_and_cohort_id($mapping->objectid, $mapping->moodleid);
        }
    }

    // Compares groups by name and returns groups not in the source groups
    private function get_new_groups($moodle_cohorts, $azure_groups, $ignore = true) {
        $results = array();
        
        foreach($azure_groups as $group) {
            //skip any non ochin-crowd groups in compare group
            if ($ignore && stripos($group['displayName'], "ochin-crowd") === false) continue;

            $exists = false;

            foreach($moodle_cohorts as $cohort) {
                //skip any non ochin-crowd groups in source group
                if (stripos($cohort->name, "ochin-crowd") === false) continue; 
                if(strtolower($cohort->name) == strtolower($group['displayName'])) $exists = true;
            }
            if(!$exists) $results[] = $group;
        }

        return $results;
    }

    // Compares groups by name and returns groups not in the source groups
    private function get_removed_groups($azure_groups, $moodle_cohorts, $ignore = true) {
        $results = array();
        
        foreach($moodle_cohorts as $cohort) {
            //skip any non ochin-crowd groups in compare group
            if ($ignore && stripos($cohort->name, "ochin-crowd") === false) continue;

            $exists = false;

            foreach($azure_groups as $group) {
                //skip any non ochin-crowd groups in source group
                if (stripos($group['displayName'], "ochin-crowd") === false) continue; 
                if(strtolower($group['displayName']) == strtolower($cohort->name)) $exists = true;
            }
            if(!$exists) $results[] = $cohort;
        }

        return $results;
    }
}
