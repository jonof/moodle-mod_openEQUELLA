<?php
// This file is part of the EQUELLA module - http://git.io/vUuof
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.
require_once ("../../config.php");
require_once ("../../course/lib.php");
require_once ("lib.php");

require_login();
$links = required_param('tlelinks', PARAM_RAW);
$courseid = required_param('course', PARAM_INT);
$sectionnum = optional_param('section', 0, PARAM_INT);

$coursecontext = context_course::instance($courseid);
require_capability('moodle/course:manageactivities', $coursecontext);

$links = json_decode($links, true);
$mod = new stdClass();
$mod->course = $courseid;
$mod->modulename = 'equella';
$module = $DB->get_record('modules', array('name' => $mod->modulename));
$mod->module = $module->id;

foreach($links as $link) {
    $mod->name = htmlspecialchars($link['name'], ENT_COMPAT, 'UTF-8');
    $mod->intro = htmlspecialchars($link['description']);
    $mod->introformat = FORMAT_HTML;
    $mod->attachmentuuid = clean_param($link['attachmentUuid'], PARAM_ALPHAEXT);
    $mod->url = clean_param($link['url'], PARAM_URL);
    $mod->metadata = serialize($link);
    $targetsection = $sectionnum;

    // if equella returns section id, overwrite moodle section parameter
    if (isset($link['folder']) && $link['folder'] != null) {
        $targetsection = clean_param($link['folder'], PARAM_INT);
    }

    if (isset($link['filename'])) {
        $mod->filename = clean_param($link['filename'], PARAM_FILE);
    }

    if (isset($link['mimeType'])) {
        $mod->mimetype = clean_param($link['mimeType'], PARAM_TEXT);
    } else {
        $mod->mimetype = mimeinfo('type', $mod->filename);
    }

    if (isset($link['activationUuid'])) {
        $mod->activation = clean_param($link['activationUuid'], PARAM_ALPHAEXT);
    }

    $equellaid = equella_add_instance($mod);

    $mod->instance = $equellaid;

    // course_modules and course_sections each contain a reference
    // to each other, so we have to update one of them twice.
    if (!$mod->coursemodule = add_course_module($mod)) {
        print_error('cannotaddcoursemodule');
    }

    if (!$addedsectionid = course_add_cm_to_section($mod->course, $mod->coursemodule, $targetsection)) {
        print_error('cannotaddcoursemoduletosection');
    }

    if (!$DB->set_field('course_modules', 'section', $addedsectionid, array('id' => $mod->coursemodule))) {
        print_error('Could not update the course module with the correct section');
    }

    set_coursemodule_visible($mod->coursemodule, true);

    if (class_exists('core\\event\\course_module_created')) {
        $cm = get_coursemodule_from_id('equella', $mod->coursemodule, 0, false, MUST_EXIST);
        $event = \core\event\course_module_created::create_from_cm($cm);
        $event->trigger();
    } else {
        $eventdata = new stdClass();
        $eventdata->modulename = $mod->modulename;
        $eventdata->name = $mod->name;
        $eventdata->cmid = $mod->coursemodule;
        $eventdata->courseid = $mod->course;
        $eventdata->userid = $USER->id;
        events_trigger('mod_created', $eventdata);
        $url = "view.php?id={$mod->coursemodule}";
        add_to_log($mod->course, $mod->modulename, 'add equella resource', $url, "$mod->modulename ID: $mod->instance", $mod->instance);
    }
}

$courseurl = new moodle_url('/course/view.php', array('id' => $courseid));
$courseurl = $courseurl->out(false);
rebuild_course_cache($courseid);
echo '<html><body>';
echo html_writer::script("window.parent.document.location='$courseurl';");
echo '</body></html>';
