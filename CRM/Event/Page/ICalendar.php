<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 * $Id$
 *
 */

/**
 * ICalendar class
 *
 */
class CRM_Event_Page_ICalendar extends CRM_Core_Page {

  /**
   * Heart of the iCalendar data assignment process. The runner gets all the meta
   * data for the event and calls the  method to output the iCalendar
   * to the user. If gData param is passed on the URL, outputs gData XML format.
   * Else outputs iCalendar format per IETF RFC2445. Page param true means send
   * to browser as inline content. Else, we send .ics file as attachment.
   *
   * @return void
   */
  public function run() {
    $id = CRM_Utils_Request::retrieve('id', 'Positive', $this, FALSE, NULL, 'GET');
    $type = CRM_Utils_Request::retrieve('type', 'Positive', $this, FALSE, 0);
    $start = CRM_Utils_Request::retrieve('start', 'Positive', $this, FALSE, 0);
    $end = CRM_Utils_Request::retrieve('end', 'Positive', $this, FALSE, 0);
    $iCalPage = CRM_Utils_Request::retrieve('list', 'Positive', $this, FALSE, 0);
    $gData = CRM_Utils_Request::retrieve('gData', 'Positive', $this, FALSE, 0);
    $html = CRM_Utils_Request::retrieve('html', 'Positive', $this, FALSE, 0);
    $rss = CRM_Utils_Request::retrieve('rss', 'Positive', $this, FALSE, 0);

	//Stoycho start - if we restrict by event id, do not restrict by date range & $onlyPublic
	$onlyPublic = true;
	if (!empty($id)) {
		$onlyPublic = false;
		if (empty($start) && empty($end)) $start = '19700101';
	}
	//Stoycho end
    $info = CRM_Event_BAO_Event::getCompleteInfo($start, $type, $id, $end, $onlyPublic);

    if(isset($info[0]['event_id'])
      && !empty($info[0]['event_id'])) {
      //Creating an object to call the zoom api and obtain the join_url
      $zoomObject = new CRM_CivirulesActions_Participant_AddToZoom;
      $zoomObject->event_id = $info[0]['event_id'];
      $joinUrl = CRM_CivirulesActions_Participant_AddToZoom::getJoinUrl($zoomObject);
      if(!empty($joinUrl)){
        $info[0]['url'] = $joinUrl;
      }
    }

    $this->assign('events', $info);
    $this->assign('timezone', @date_default_timezone_get());

    // Send data to the correct template for formatting (iCal vs. gData)
    $template = CRM_Core_Smarty::singleton();
    $config = CRM_Core_Config::singleton();
    if ($rss) {
      // rss 2.0 requires lower case dash delimited locale
      $this->assign('rssLang', str_replace('_', '-', strtolower($config->lcMessages)));
      $calendar = $template->fetch('CRM/Core/Calendar/Rss.tpl');
    }
    elseif ($gData) {
      $calendar = $template->fetch('CRM/Core/Calendar/GData.tpl');
    }
    elseif ($html) {
      // check if we're in shopping cart mode for events
      $enable_cart = Civi::settings()->get('enable_cart');
      if ($enable_cart) {
        $this->assign('registration_links', TRUE);
      }
      return parent::run();
    }
    else {
      $calendar = $template->fetch('CRM/Core/Calendar/ICal.tpl');
      $calendar = preg_replace('/(?<!\r)\n/', "\r\n", $calendar);
    }
    // Push output for feed or download
    if ($iCalPage == 1) {
      if ($gData || $rss) {
        CRM_Utils_ICalendar::send($calendar, 'text/xml', 'utf-8');
      }
      else {
        CRM_Utils_ICalendar::send($calendar, 'text/plain', 'utf-8');
      }
    }
    else {
      CRM_Utils_ICalendar::send($calendar, 'text/calendar', 'utf-8', 'civicrm_ical.ics', 'attachment');
    }
    CRM_Utils_System::civiExit();
  }

}