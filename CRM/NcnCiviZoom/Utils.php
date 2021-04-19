<?php

/**
 *  NcnCiviZoom utils functions
 *
 * @package CiviCRM
 */
class CRM_NcnCiviZoom_Utils {

  /*
   * Function to retrieve zoom settings
   * Full settings if account id is passed
   * Only common settings if id nt passed
   */
  public static function getZoomSettings($id=null){
    $settings = CRM_Core_BAO_Setting::getItem(ZOOM_SETTINGS, 'zoom_settings');
    if(!empty($id) && !empty($settings)){
      return array_merge($settings, self::getZoomAccountSettingsByIdOrName($id));
    } else{
      return $settings;
    }
  }

  public static function getWebinarCustomField(){
    $settings = self::getZoomSettings();
    $customId = CRM_Utils_Array::value('custom_field_id_webinar', $settings, NULL);
    $customField = (!empty($customId))? 'custom_'.$customId : NULL;
    return $customField;
  }

  public static function getMeetingCustomField(){
    $settings = self::getZoomSettings();
    $customId = CRM_Utils_Array::value('custom_field_id_meeting', $settings, NULL);
    $customField = (!empty($customId))? 'custom_'.$customId : NULL;
    return $customField;
  }

  public static function getAccountIdCustomField(){
    $settings = self::getZoomSettings();
    $customId = CRM_Utils_Array::value('custom_field_account_id', $settings, NULL);
    $customField = (!empty($customId))? 'custom_'.$customId : NULL;
    return $customField;
  }

  /*
   * Output will be an array of all zoom settings
   * as id => [zoom settings]
   */
  public function getAllZoomAccountSettings() {
    $tableName = CRM_NcnCiviZoom_Constants::ZOOM_ACCOUNT_SETTINGS;
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM {$tableName}");
    $zoomSettings = [];
    while ($dao->fetch()) {
      $zoomSettings[$dao->id] = $dao->toArray();
    }
    return $zoomSettings;
  }

  /*
   * Output will be an array as [zoom settings]
   */
  public function getZoomAccountSettingsByIdOrName($id=NULL, $name = null) {
    if(empty($id) && empty($name)){
      return null;
    }
    $zoomSettings = self::getAllZoomAccountSettings();
    if($id && !empty($zoomSettings[$id])){
      return $zoomSettings[$id];
    } elseif ($name) {
      foreach ($zoomSettings as $id => $settings) {
        if($settings[$name] == $name){
          return $settings;
        }
      }
    }

    return null;
  }

  /*
   * Output will be an array of all settings' ids and names * as id => 'name'
   */
  public function getAllZoomSettingsNamesAndIds(){
    $zoomSettings = self::getAllZoomAccountSettings();
    $zoomList[0] = "--select--";
    if(!empty($zoomSettings)){
      foreach ($zoomSettings as $key => $value) {
        $zoomList[$key] = $value['name'];
      }
    }

    return $zoomList;
  }

  public function getZoomAccountIdByEventId($eventId){
    $result = null;
    $customField = self::getAccountIdCustomField();
    try {
      $apiResult = civicrm_api3('Event', 'get', [
        'sequential' => 1,
        'return' => [$customField],
        'id' => $eventId,
      ]);
      // Remove any empty spaces
      $result = trim($apiResult['values'][0][$customField]);
      $result = str_replace(' ', '', $result);
    } catch (Exception $e) {
      throw $e;
    }

    return $result;
  }

  public function getZoomSettingsByEventId($eventId){
    $settings = [];
    $accountId = self::getZoomAccountIdByEventId($eventId);
    if(!empty($accountId)){
      $settings = self::getZoomSettings($accountId);
    }
    return $settings;
  }

  // Please Don't use this function now
  // public function getRegionalZoomAccountId(){
  //   $regionalAcountId = null;
  //   $id = 1;//Harcoded Regional zoom account Id
  //   $tableName = CRM_NcnCiviZoom_Constants::ZOOM_ACCOUNT_SETTINGS;
  //   $regionalAcountId = CRM_Core_DAO::singleValueQuery("SELECT id FROM ".$tableName." WHERE id = %1", [1=>[$id ,'Integer']]);
  //   return $regionalAcountId;
  // }

  public static function checkRequiredProfilesForAnEvent($profileIds = [], $checkFields = []) {
    if(empty($profileIds) || empty($checkFields)){
      return null;
    }
    if(!is_array($profileIds)){
      $profileIds = [$profileIds];
    }
    if(!is_array($checkFields)){
      $checkFields = [$checkFields];
    }
    foreach ($checkFields as $checkField) {
      $requiredFields[$checkField] = 0;
    }
    $missingFields = [];
    foreach ($profileIds as $profileId) {
      if(!empty($profileId)){
        try {
          $profileDetails = civicrm_api3('UFField', 'get', [
            'sequential' => 1,
            'uf_group_id' => $profileId,
          ]);
        } catch (Exception $e) {
          CRM_Core_Error::debug_var('CRM_NcnCiviZoom_Utils checkRequiredProfilesForAnEvent error', $e);
        }
        if(isset($profileDetails['values'])){
          foreach ($profileDetails['values'] as $profileDetail) {
            if(array_key_exists($profileDetail['field_name'], $requiredFields)){
              $requiredFields[$profileDetail['field_name']] = 1;
            }
          }//End of inner foreach
        }
      }
    }//End of outer foreach

    // Getting missing fields
    foreach ($requiredFields as $requiredField => $value) {
      if($value != 1){
        $missingFields[] = $requiredField;
      }
    }
    return $missingFields;
  }

  public function addZoomListToEventForm(&$form){

    $zoomList = self::getAllZoomSettingsNamesAndIds();

    $form->add(
      'select',
      'zoom_account_list',
      'Select the zoom account',
      $zoomList,
      FALSE,
      array('class' => 'medium', 'multiple' => FALSE, 'id' => 'zoom_account_list')
    );

    $customIds['Webinar'] = self::getWebinarCustomField();
    $customIds['Meeting'] = self::getMeetingCustomField();
    $customFieldZoomAccount = self::getAccountIdCustomField();
    $form->assign('customIdWebinar',$customIds['Webinar'].'_');
    $form->assign('customIdMeeting',$customIds['Meeting'].'_');
    $form->assign('accountId',$customFieldZoomAccount.'_');
    $eventId = null;
    if(!empty($form->_id)){
      $eventId = $form->_id;
    }elseif (!empty($form->_entityId)) {
      $eventId = $form->_entityId;
    }
    if(!empty($eventId)){
      if(($form->getAction() == CRM_Core_Action::UPDATE) && !empty($customFieldZoomAccount)){
        try {
          $apiResult = civicrm_api3('CustomValue', 'get', [
            'sequential' => 1,
            'entity_id' => $eventId,
            'return.'.$customFieldZoomAccount => 1,
          ]);
        } catch (Exception $e) {
          CRM_Core_Error::debug_var('Api error, Entity => CustomValue , action => get ', $e);
        }
        if(!empty($apiResult['values'][0]['latest'])){
          if(array_key_exists($apiResult['values'][0]['latest'], $zoomList)){
            $form->setDefaults(['zoom_account_list' => $apiResult['values'][0]['latest']]);
          }
        }
      }
    }
  }

  /**
   * Union two given arrays
   *
   * @param array1 - Array
   * @param array2 - Array
   * @return union - Array
   */
  public static function multiDimArrayUnion($array1 = [], $array2 = []){
    if(!is_array($array1)){
      return $array1;
    }

    if(!is_array($array2)){
      $array2 = [];
    }
    $merged = array_merge($array1 , $array2);
    foreach ($merged as $key => $value) {
      $serialized[$key] = serialize($value);
    }
    $serialized = array_unique($serialized);
    foreach ($serialized as $key => $value) {
      $union[$key] = unserialize($value);
    }

    return $union;
  }

  /**
   * Upcoming Events List
   *
   * @return Array of events
   */
  public static function getUpcomingEventsList(){
    $today = date("Y-m-d");

    $startDate = civicrm_api3('Event', 'get', [
      'start_date' => ['>=' => $today],
    ]);

    $endDate = civicrm_api3('Event', 'get', [
      'end_date' => ['>=' => $today],
    ]);

    return self::multiDimArrayUnion($startDate['values'], $endDate['values']);
  }

  /**
   * Filter recent registrants list by time(in mins)
   *
   * @param registrantsList - Array
   * @param minsBack - Integer
   * @return recentRegistrants - Array
   */
  public static function filterZoomRegistrantsByTime($registrantsList = [], $minsBack = 60){
    if(empty($registrantsList) || !is_array($registrantsList)){
      return;
    }
    $recentRegistrants = [];
    foreach ($registrantsList as $registrant) {
      $seconds = strtotime("now") - strtotime($registrant['create_time']);
      $mins = ($seconds/60);
      if($mins < $minsBack){
        $recentRegistrants[] = $registrant;
      }
    }

    return $recentRegistrants;
  }

  /**
   * String of Registrants
   *
   * @param registrantsList - Array
   * @param glue - String
   * @return stringOfRegistrants - String
   */
  public static function stringOfRegistrants($registrantsList = [], $glue = ' , '){
    if(empty($registrantsList) || !is_array($registrantsList)){
      return;
    }
    $registrantsUpdateArray = [];
    foreach ($registrantsList as $registrant) {
      $registrantsUpdateArray[] = $registrant['first_name']." ".$registrant['last_name']." - ".$registrant['email'];
    }
    $stringOfRegistrants = implode($glue, $registrantsUpdateArray);
    return $stringOfRegistrants;
  }

  /**
   * Update the Zoom Registrants to event's notes
   *
   * @param eventId - Integer
   * @param registrantsList - Array
   */
  public static function updateZoomRegistrantsToNotes($eventId, $registrantsList = []){
    $updateResult = '';
    if(empty($eventId) || empty($registrantsList) || !is_array($registrantsList)){
      $updateResult = 'Params Missing';
      return $updateResult;
    }

    $updateString = self::stringOfRegistrants($registrantsList);
    $cFNameEventNotes = CRM_NcnCiviZoom_Constants::CF_Event_Zoom_Notes;

    try {
      $cFDetails = civicrm_api3('CustomField', 'get', [
        'sequential' => 1,
        'name' => $cFNameEventNotes,
      ]);
    } catch (Exception $e) {
      CRM_Core_Error::debug_var('Error in updateZoomRegistrantsToNotes', $e);
      CRM_Core_Error::debug_var('Error while calling api CustomField get', $cFNameEventNotes);
      $updateResult = "Couldn't retrieve the Custom Field ".$cFNameEventNotes." data";
    }
    if(!empty($cFDetails['id'])){
      try {
        $apiResult = civicrm_api3('CustomValue', 'create', [
          'entity_id' => $eventId,
          'custom_'.$cFDetails['id'] => $updateString.".",
        ]);
      } catch (Exception $e) {
        CRM_Core_Error::debug_var('Error in updateZoomRegistrantsToNotes', $e);
        CRM_Core_Error::debug_var('Error while calling api CustomField create', [
          'eventId' => $eventId,
          'cFDetails' => $cFDetails,
          'updateString' => $updateString
        ]);
      }
      if($apiResult['values']){
        $updateResult = 'Registrants have been updated to the event successfully.';
      }
    }

    return $updateResult;
  }

  /*
   * Function to get message template details
   */
  public static function getMessageTemplateDetails($title = null, $id = null) {
    if(!empty($title)){
      $result = civicrm_api3('MessageTemplate', 'get', array(
        'sequential' => 1,
        'msg_title' => $title,
      ));

      return $result ['values'][0];
    }elseif(!empty($id)){
      $result = civicrm_api3('MessageTemplate', 'get', array(
        'sequential' => 1,
        'id' => $id,
      ));

      return $result ['values'][0];
    }else{
      return [];
    }
  }

  /**
   * Send Registrants as Email
   *
   * @param toEmails - String
   * @param registrantsList - Array
   * @param event - Integer
   */
  public static function sendZoomRegistrantsToEmail($toEmails, $registrantsList = [], $event){
    if(empty($toEmails) || empty($registrantsList)){
      return;
    }

    // $msgTitle = CRM_NcnCiviZoom_Constants::SEND_ZOOM_REGISTRANTS_EMAIL_TEMPLATE_TITLE;
    $msgId = CRM_NcnCiviZoom_Utils::getEmailTemplateIdToSendZoomRegistrants();
    $emailContent = self::getMessageTemplateDetails(null, $msgId);
    if(empty($emailContent)){
      return 'Email Template Not found.';
    }
    $return = [];
    // Replacing the tokens
    $emailContent['subject'] = str_replace('{event_title}' ,$event['title'], $emailContent['msg_subject']);
    $registrantsString = self::stringOfRegistrants($registrantsList, '<br>');
    $tokens_array = array('{registrants}', '{event_title}' , '{event_start_date}', '{event_id}');
    $replace_array = array($registrantsString, $event['title'], $event['event_start_date'], $event['id']);
    // Retrieve the custom fields
    $eventCFields = CRM_Core_BAO_CustomField::getFields('Event');
    foreach ($eventCFields as $cField) {
      $cFName = $cField['name'];
      // Check if the custom field is included and replace the token
      if(strpos($emailContent['msg_html'], '{'.$cFName.'}' )){
        $tokens_array[] = '{'.$cFName.'}';
        $eventCustValue = civicrm_api3('Event', 'getsingle', [
          'return' => [$cFName],
          'id' => $event['id'],
        ]);
        $replace_array[] = empty($eventCustValue[$cFName]) ? '' : $eventCustValue[$cFName];
      }
    }
    $emailContent['html'] = str_replace($tokens_array, $replace_array, $emailContent['msg_html']);
    $emailIds = explode(',', $toEmails);
    foreach ($emailIds as $emailId) {
      $emailSent = self::sendEmail($emailId, $emailContent);
      if($emailSent){
        $return['email_message'][] = 'Email has been Sent to '.$emailId;
      }else{
        $return['email_message'][] = "Email couldn't be Sent to ".$emailId;
      }
    }

    return $return;
  }

  /**
   * Function to send email
   */
  public static function sendEmail($email, $emailContent) {
    $emailSent = FALSE;
    if (empty($email) || empty($emailContent)) {
      return $emailSent;
    }

    $mailParams['toName'] = $email;
    $mailParams['toEmail'] = $email;

    $mailParams['text'] = !empty($emailContent['text']) ? $emailContent['text'] : '';
    $mailParams['html'] = !empty($emailContent['html']) ? $emailContent['html'] : '';
    $mailParams['subject'] = !empty($emailContent['subject']) ? $emailContent['subject'] : '';
    $defaultAddress = CRM_Core_OptionGroup::values('from_email_address', NULL, NULL, NULL, ' AND is_default = 1');
    $mailParams['from'] = reset($defaultAddress);

    require_once 'CRM/Utils/Mail.php';
    $emailSent = CRM_Utils_Mail::send($mailParams);

    return $emailSent;
  }

  public static function forUpgrade1003(){
    $customGroupName = CRM_NcnCiviZoom_Constants::CG_Event_Zoom_Notes;
    $customFieldName = CRM_NcnCiviZoom_Constants::CF_Event_Zoom_Notes;

    $customGroupDetails = civicrm_api3('CustomGroup', 'create', [
      'sequential' => 1,
      'title' => "Event Zoom Notes",
      'extends' => "Event",
      'name' => $customGroupName,
    ]);

    civicrm_api3('CustomField', 'create', [
      'sequential' => 1,
      'custom_group_id' => $customGroupDetails['values'][0]['id'],
      'label' => "Event Zoom Notes",
      'name' => $customFieldName,
      'data_type' => "Memo",
      'html_type' => "TextArea",
      'is_view' => 1,
    ]);

    $sendZoomRegistrantsEmailTemplateTitle = CRM_NcnCiviZoom_Constants::SEND_ZOOM_REGISTRANTS_EMAIL_TEMPLATE_TITLE;
    $msgHtml = "<br> {event_title} <br> {registrants} <br> {event_start_date} <br> {event_id} <br>";
    $msgSubject = "Recently Joined to the zoom event: {event_title}";
    civicrm_api3('MessageTemplate', 'create', [
      'msg_title' => $sendZoomRegistrantsEmailTemplateTitle,
      'msg_html' => $msgHtml,
      'msg_subject' => $msgSubject,
    ]);
  }

  public static function forUpgrade1004(){
    $sendZoomRegistrantsEmailTemplateTitle = CRM_NcnCiviZoom_Constants::SEND_ZOOM_REGISTRANTS_EMAIL_TEMPLATE_TITLE;
    $templateDetails = civicrm_api3('MessageTemplate', 'get', [
      'sequential' => 1,
      'msg_title' => $sendZoomRegistrantsEmailTemplateTitle,
    ]);
    $zoomSettings = self::getZoomSettings();
    if(!empty($templateDetails['id'])){
      $zoomSettings['registrants_email_template_id'] = $templateDetails['id'];
    }
    CRM_Core_BAO_Setting::setItem($zoomSettings, ZOOM_SETTINGS, 'zoom_settings');
  }

  public static function getEmailTemplateIdToSendZoomRegistrants(){
    $settings = self::getZoomSettings();
    $templateId = CRM_Utils_Array::value('registrants_email_template_id', $settings, NULL);
    return $templateId;
  }

  /**
   *
   * @return $syncZoomDataFields type-array of zoom selected zoom fields
   */
  public static function getSyncZoomDataFields(){
    $settings = self::getZoomSettings();
    $syncZoomDataFields = CRM_Utils_Array::value('sync_zoom_data_fields', $settings, []);
    return $syncZoomDataFields;
  }

  /**
   * Updates the given zoom data against the partcipant record
   * It only updates the fields selected in the Sync Zoom Data form
   *
   * @param $participantId type-int
   * @param $zoomData type-array of zoom data
   * @return bool - updated or not.
   */
  public static function updateZoomParticipantData($participantId, $zoomData = []){
    if(empty($participantId) || empty($zoomData)){
      return FALSE;
    }
    // Modifying some keys as per the custom field names
    if(!empty($zoomData['user_email'])){
      $zoomData['email'] = $zoomData['user_email'];
    }
    if(!empty($zoomData['id'])){
      $zoomData['registrant_id'] = $zoomData['id'];
    }
    // Converting the zoom duration into minutes
    if(!empty($zoomData['duration'])){
      $zoomData['duration'] = intdiv($zoomData['duration'], 60);
    }

    $cGName = CRM_NcnCiviZoom_Constants::CG_ZOOM_DATA_SYNC;
    try {
        $cGId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_CustomGroup', $cGName, 'id', 'name');
    } catch (Exception $e) {
        // Handle error here.
        $errorMessage = $e->getMessage();
        CRM_Core_Error::debug_var('CRM_NcnCiviZoom_Utils::updateZoomParticipantData error details', $errorMessage);
        CRM_Core_Error::debug_var('Custom Group seems  does not exist- custom group name', $cGName);
        return FALSE;
    }

    if(empty($cGId)){
      return FALSE;
    }

    // Get the selected custom fields names
    $syncFields = self::getSyncZoomDataFields();
    $updateParams = [];
    // If duration has been selected, then add the multiple entries data also
    if(array_key_exists('duration', $syncFields)){
      for ($count = 1; $count <= 20 ; $count++) {
        if(!empty($zoomData['duration_'.$count])){
          $syncFields['duration_'.$count] = 1;
        }
      }
    }
    foreach ($syncFields as $syncField => $bool) {
      try {
        $cFDetails = civicrm_api3('CustomField', 'get', [
          'sequential' => 1,
          'custom_group_id' => $cGId,
          'name' => $syncField,
        ]);
      } catch (Exception $e) {
        // Handle error here.
        $errorMessage = $e->getMessage();
        CRM_Core_Error::debug_var('CRM_NcnCiviZoom_Utils::updateZoomParticipantData Api:CustomField Action:get error details', $errorMessage);
        continue;
      }

      if(!empty($cFDetails['id']) && !empty($zoomData[$syncField])){
        // Creating update params for each custom field
        $updateParams['custom_'.$cFDetails['id']] = $zoomData[$syncField];
        if($syncField == 'join_time' || $syncField == 'leave_time') {
          $updateParams['custom_'.$cFDetails['id']] = date('YmdHis', strtotime($zoomData[$syncField]));
        }
        if('duration_' == substr($syncField, 0, 9)) {
          $updateParams['custom_'.$cFDetails['id']] = intdiv($zoomData[$syncField], 60);
        }
      }
    }

    if(!empty($updateParams)){
      $updateParams['entity_id'] = $participantId;
      try{
        $updateResult = civicrm_api3('CustomValue', 'create', $updateParams);
      } catch (CiviCRM_API3_Exception $e) {
        // Handle error here.
        $errorMessage = $e->getMessage();
        CRM_Core_Error::debug_var('CRM_NcnCiviZoom_Utils::updateZoomParticipantData Api:CustomValue Action:create errorMessage', $errorMessage);
        CRM_Core_Error::debug_var('CRM_NcnCiviZoom_Utils::updateZoomParticipantData Api:CustomValue Action:create updateParams', $updateParams);
        return FALSE;
      }
      return $updateResult['values'];
    }else{
      return FALSE;
    }
  }

  // MV: function to validate the meeting/webinar belongs to the account user id
  public static function validateMeetingWebinarByUserId($params) {
    if (!empty($params['user_id']) && !empty($params["entityID"])) {
      $entity = $params['entity'];
      $eventList = CRM_NcnCiviZoom_Utils::getMeetingsWebinarsByUserId($params);

      // If API call code 200 ( success ) but error with user id then return error message.
      if (!isset($eventList['page_size']) && !empty($eventList['message'])) {
        return $eventList;
      }
      // else if user doesn't have any meeting or webinar then return status message
      elseif (isset($eventList['page_size']) && empty($eventList['total_records'])) {
        return ['message' => "No {$entity} found for this user."];
      }
      else{
        $key = ($params['entity'] == "Meeting") ? 'meetings' : 'webinars';

        $userID = CRM_Utils_Array::value('user_id', $params);
        $entityID = CRM_Utils_Array::value('entityID', $params);
        $entityList = CRM_Utils_Array::value("{$key}_options", $eventList);

        if (empty($entityList)) {
          return ['message' => "No {$entity} found for this user."];
        }
        // if meeting/webinar id not belong to this user then return error.
        if (!array_key_exists($entityID, $entityList)) {
          return ['message' => "{$entity} ID ($entityID) not found for this user ID: {$userID} "];
        }

        return $eventList;
      }
    }

    return FALSE;
  }

  // MV: function to get list of meetings/webinars by account user id.
  public static function getMeetingsWebinarsByUserId($params) {
    if (!empty($params['user_id']) && !empty($params["entity"])) {
      $entity = ($params['entity'] == "Meeting") ? 'meetings' : 'webinars';

      $settings = CRM_NcnCiviZoom_Utils::getZoomSettings($params["account_id"]);
      $url = $settings['base_url'] . "/users/".$params['user_id']."/".$entity."/";
      // fetch all Meeting/Webinar belong to this user.
      list($isResponseOK, $result) = CRM_CivirulesActions_Participant_AddToZoom::requestZttpWithHeader($params["account_id"], $url);

      CRM_Core_Error::debug_var('getMeetingsWebinarsByUserId-isResponseOK', $isResponseOK);

      if($isResponseOK){
        $eventList = CRM_Utils_Array::value($entity, $result);

        if (empty($eventList) && !empty($result['message'])) {
          return ["status" => 0, "message" => $result['message']];
        }

        $entityOptions = [];
        foreach ($eventList as $key => $value) {
          $entityOptions[$value['id']] = $value['topic'];
        }

        $result["{$entity}_options"] = $entityOptions;
        return $result;
      } else {
        return ["status" => 0, "message" => "User ID: ".$params["user_id"]." does not exists"];
      }
    }
  }

  /*
   * Function to add Zoom exception custom field
   */
  public static function forUpgrade1006(){
    $cGName = CRM_NcnCiviZoom_Constants::CG_Event_Zoom_Notes;
    $cFName = CRM_NcnCiviZoom_Constants::CF_Unmatched_Zoom_Participants;

    $cGId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_CustomGroup', $cGName, 'id', 'name');
    if(!empty($cGId)){
      civicrm_api3('CustomField', 'create', [
        'sequential' => 1,
        'custom_group_id' => $cGId,
        'label' => "Unmatched Zoom Participants",
        'name' => $cFName,
        'data_type' => "Memo",
        'html_type' => "TextArea",
        'column_name' => 'unmatched_zoom_participants',
        'is_view' => 1,
      ]);
    }
  }

  /**
   * String of Participants
   *
   * @param participantsList - Array
   * @param glue - String
   * @return stringOfParticipants - String
   */
  public static function stringOfParticipants($participantsList = [], $glue = ' , '){
    if(empty($participantsList) || !is_array($participantsList)){
      return;
    }
    $participantsUpdateArray = [];
    foreach ($participantsList as $participant) {
      $participantsUpdateArray[] = $participant['name']." - ".$participant['user_email'];
    }
    $stringOfParticipants = implode($glue, $participantsUpdateArray);
    return $stringOfParticipants;
  }

  /**
   * Update the update Unmatched Zoom Participants to event's notes
   * These are the Zoom participants who donot have a matching participant record in the civi
   *
   * @param eventId - Integer
   * @param exceptionList - Array
   */
  public static function updateUnmatchedZoomParticipantsToNotes($eventId, $exceptionList = []){
    $updateResult = '';
    if(empty($eventId) || empty($exceptionList) || !is_array($exceptionList)){
      $updateResult = 'Params Missing';
      return $updateResult;
    }

    $updateString = self::stringOfParticipants($exceptionList);
    $cFName = CRM_NcnCiviZoom_Constants::CF_Unmatched_Zoom_Participants;

    try {
      $cFDetails = civicrm_api3('CustomField', 'get', [
        'sequential' => 1,
        'name' => $cFName,
      ]);
    } catch (Exception $e) {
      CRM_Core_Error::debug_var('Error in updateUnmatchedZoomParticipantsToNotes', $e);
      CRM_Core_Error::debug_var('Error while calling api CustomField get', $cFName);
      $updateResult = "Couldn't retrieve the Custom Field ".$cFName." data";
    }
    if(!empty($cFDetails['id'])){
      try {
        $apiResult = civicrm_api3('CustomValue', 'create', [
          'entity_id' => $eventId,
          'custom_'.$cFDetails['id'] => $updateString.".",
        ]);
      } catch (Exception $e) {
        CRM_Core_Error::debug_var('Error in updateUnmatchedZoomParticipantsToNotes', $e);
        CRM_Core_Error::debug_var('Error while calling api CustomField create', [
          'eventId' => $eventId,
          'cFDetails' => $cFDetails,
          'updateString' => $updateString
        ]);
      }
      if($apiResult['values']){
        $updateResult = 'Exceptions have been updated to the event successfully.';
      }
    }

    return $updateResult;
  }

  /*
   * Function to add Zoom join link custom field
   */
  public static function forUpgrade1007(){
    $cGName = CRM_NcnCiviZoom_Constants::CG_Event_Zoom_Notes;
    $cFName = CRM_NcnCiviZoom_Constants::CF_ZOOM_JOIN_LINK;

    $cGId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_CustomGroup', $cGName, 'id', 'name');
    if(!empty($cGId)){
      civicrm_api3('CustomField', 'create', [
        'sequential' => 1,
        'custom_group_id' => $cGId,
        'label' => "Zoom Join Link",
        'name' => $cFName,
        'data_type' => "String",
        'html_type' => "Text",
        'column_name' => 'zoom_join_link',
        'is_view' => 1,
      ]);
    }
  }

  /*
   * Function to add Zoom participant join link custom field
   */
  public static function forUpgrade1008(){
    $cGName = CRM_NcnCiviZoom_Constants::CG_ZOOM_DATA_SYNC;
    $cFName = CRM_NcnCiviZoom_Constants::CF_ZOOM_PARTICIPANT_JOIN_LINK;

    $cGId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_CustomGroup', $cGName, 'id', 'name');
    if(!empty($cGId)){
      civicrm_api3('CustomField', 'create', [
        'sequential' => 1,
        'custom_group_id' => $cGId,
        'label' => "Zoom Participant Join Link",
        'name' => $cFName,
        'data_type' => "String",
        'html_type' => "Text",
        'column_name' => 'zoom_participant_join_link',
        'is_view' => 1,
      ]);
    }
  }

  /*
   * Function to get participant Id
   */
  public static function getParticipantId($contactId, $eventId){
    if(empty($contactId) || empty($eventId)){
      CRM_Core_Error::debug_log_message('Required Params Missing in '.__CLASS__.'::'.__FUNCTION__);
      CRM_Core_Error::debug_var('contactId', $contactId);
      CRM_Core_Error::debug_var('eventId', $eventId);
      return;
    }

    try {
      $participantDetals = civicrm_api3('Participant', 'get', [
        'sequential' => 1,
        'contact_id' => $contactId,
        'event_id' => $eventId,
      ]);
    } catch (Exception $e) {
      CRM_Core_Error::debug_log_message('Error while calling Participant get api in '.__CLASS__.'::'.__FUNCTION__);
      CRM_Core_Error::debug_var('contactId', $contactId);
      CRM_Core_Error::debug_var('eventId', $eventId);
    }
    if(!empty($participantDetals['id'])){
      return $participantDetals['id'];
    }
    return;
  }

  /*
   * Function to update Zoom participant join link custom field
   */
  public static function updateZoomParticipantJoinLink($pId, $zoom_join_link){
    if(empty($pId) || empty($zoom_join_link)){
      CRM_Core_Error::debug_log_message('Required Params Missing in '.__CLASS__.'::'.__FUNCTION__);
      CRM_Core_Error::debug_var('pId', $pId);
      CRM_Core_Error::debug_var('zoom_join_link', $zoom_join_link);
      return FALSE;
    }
    $cGName = CRM_NcnCiviZoom_Constants::CG_ZOOM_DATA_SYNC;
    $cFName = CRM_NcnCiviZoom_Constants::CF_ZOOM_PARTICIPANT_JOIN_LINK;

    $cGId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_CustomGroup', $cGName, 'id', 'name');

    if(!empty($cGId)){
      $cFDetails = civicrm_api3('CustomField', 'get', [
        'sequential' => 1,
        'custom_group_id' => $cGId,
        'name' => $cFName,
      ]);
      if(!empty($cFDetails['id'])){
        $customValueWritten = civicrm_api3('CustomValue', 'create', [
          'sequential' => 1,
          'entity_id' => $pId,
          'custom_'.$cFDetails['id'] => $zoom_join_link,
        ]);
        return !$customValueWritten['is_error'];
      }
    }
    return FALSE;
  }

  /*
   * Function to create zoom registrants table
   */
  public static function forUpgrade1009(){
    $tableName = CRM_NcnCiviZoom_Constants::ZOOM_REGISTRANTS_TABLE_NAME;
    $createTableQuery = "
      CREATE TABLE IF NOT EXISTS civicrm_zoom_registrants (
      `id` int unsigned NOT NULL PRIMARY KEY UNIQUE AUTO_INCREMENT  COMMENT 'Id',
      `event_id` int unsigned NOT NULL COMMENT 'FK to Event ID',
      `first_name` varchar(255),
      `last_name` varchar(255),
      `email` varchar(255),
      CONSTRAINT FK_civicrm_zoom_registrants_event_id FOREIGN KEY (event_id) REFERENCES civicrm_event(id) ON DELETE CASCADE)
      ENGINE=InnoDB"
    ;
    CRM_Core_DAO::executeQuery($createTableQuery);
    $uniqueIndexQuery = "CREATE UNIQUE INDEX Idx_event_id_email ON civicrm_zoom_registrants (event_id, email)";
    CRM_Core_DAO::executeQuery($uniqueIndexQuery);
  }

  /*
   * Function to get zoom registrants for an event
   */
  public static function getZoomRegistrantsFromCivi($eventId = NULL){
    $zoomRegistrants = array();
    if(!empty($eventId)){
      $tableName = CRM_NcnCiviZoom_Constants::ZOOM_REGISTRANTS_TABLE_NAME;
      $getZoomRegistrantsQuery = "SELECT * FROM ".$tableName." WHERE event_id = %1";
      $qParams = array(
        1 => array($eventId, 'Integer')
      );
      $dao = CRM_Core_DAO::executeQuery($getZoomRegistrantsQuery, $qParams);
      while ($dao->fetch()) {
        $zoomRegistrants[] = $dao->toArray();
      }
    }

    return $zoomRegistrants;
  }

  /*
   * Function to insert zoom registrants for an event
   */
  public static function insertZoomRegistrantsInToCivi($eventId = NULL, $registrantsList = array()){
    if(empty($eventId) || empty($registrantsList) || !is_array($registrantsList)){
      CRM_Core_Error::debug_log_message('Required Params Missing in '.__CLASS__.'::'.__FUNCTION__);
      return FALSE;
    }

    $tableName = CRM_NcnCiviZoom_Constants::ZOOM_REGISTRANTS_TABLE_NAME;
    $insertQuery = 'INSERT INTO '.$tableName.' (event_id, first_name, last_name, email) VALUES ';
    $qParamsString = '';
    $qParamsArray = array();
    $qParams = array();
    $i = 2;
    $qParams[1] = array($eventId, 'Integer');
    foreach ($registrantsList as $key => $registrant) {
      $qParamsArray[$key][] = '%1';
      if(!empty($registrant['first_name'])){
        $qParams[$i] = array($registrant['first_name'], 'String');
      }else{
        $qParams[$i] = array('', 'String');
      }
      $qParamsArray[$key][] = '%'.$i;
      $i++;
      if(!empty($registrant['last_name'])){
        $qParams[$i] = array($registrant['last_name'], 'String');
      }else{
        $qParams[$i] = array('', 'String');
      }
      $qParamsArray[$key][] = '%'.$i;
      $i++;
      if(!empty($registrant['email'])){
        $qParams[$i] = array($registrant['email'], 'String');
      }else{
        $qParams[$i] = array('', 'String');
      }
      $qParamsArray[$key][] = '%'.$i;
      $i++;
    }
    $rowStrings = array();
    foreach ($qParamsArray as $eachRow) {
      $rowStrings[] = ' ('.implode(" , ",$eachRow).')';
    }
    $qParamsString = implode(" , ", $rowStrings);
    $insertQuery .= $qParamsString;
    $insertQuery .= '
      ON DUPLICATE KEY UPDATE
      event_id   = VALUES(event_id),
      first_name = VALUES(first_name),
      last_name  = VALUES(last_name),
      email      = VALUES(email)';
    CRM_Core_DAO::executeQuery($insertQuery, $qParams);
  }

  /*
   * Function to get Zoom Registrant Details By Id
   */
  public static function getZoomRegistrantDetailsById($Id){
    $zoomRegistrant = array();
    if(empty($Id)){
      return $zoomRegistrant;
    }
    $tableName = CRM_NcnCiviZoom_Constants::ZOOM_REGISTRANTS_TABLE_NAME;
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM ".$tableName." WHERE id = ".$Id);
    while ($dao->fetch()) {
      $zoomRegistrant = $dao->toArray();
    }
    return $zoomRegistrant;
  }
}
