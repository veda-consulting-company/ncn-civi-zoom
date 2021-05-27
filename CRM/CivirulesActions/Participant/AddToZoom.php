<?php

use Firebase\JWT\JWT;
use Zttp\Zttp;

class CRM_CivirulesActions_Participant_AddToZoom extends CRM_Civirules_Action{

	/**
	 * Method processAction to execute the action
	 *
	 * @param CRM_Civirules_TriggerData_TriggerData $triggerData
	 * @access public
	 *
	 */
	public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData) {
	  $contactId = $triggerData->getContactId();
	  $event = $triggerData->getEntityData('Event');
	  $webinar = $this->getWebinarID($event['id']);
	  $participant = $this->getContactData($contactId);
	  $meeting = $this->getMeetingID($event['id']);
	  if(!empty($meeting)){
	  	$this->addParticipant($participant, $meeting, $triggerData, 'Meeting');
	  } elseif (!empty($webinar)) {
	  	$this->addParticipant($participant, $webinar, $triggerData, 'Webinar');
	  }
	}

	/**
	 * Get an event's webinar id
	 * @param  int $event The event's id
	 * @return string The event's webinar id
	 */
	private function getWebinarID($event) {
		$result;
		$customField = CRM_NcnCiviZoom_Utils::getWebinarCustomField();
		try {
			$apiResult = civicrm_api3('Event', 'get', [
			  'sequential' => 1,
			  'return' => [$customField],
			  'id' => $event,
			]);
			$result = null;
			if(!empty($apiResult['values'][0][$customField])){
				// Remove any empty spaces
				$result = trim($apiResult['values'][0][$customField]);
				$result = str_replace(' ', '', $result);
			}
		} catch (Exception $e) {
			throw $e;
		}

		return $result;
	}

	/**
	 * Get an event's Meeting id
	 * @param  int $event The event's id
	 * @return string The event's Meeting id
	 */
	private function getMeetingID($event) {
		$result;
		$customField = CRM_NcnCiviZoom_Utils::getMeetingCustomField();
		try {
			$apiResult = civicrm_api3('Event', 'get', [
			  'sequential' => 1,
			  'return' => [$customField],
			  'id' => $event,
			]);
			$result = null;
			if(!empty($apiResult['values'][0][$customField])){
				// Remove any empty spaces
				$result = trim($apiResult['values'][0][$customField]);
				$result = str_replace(' ', '', $result);
			}
		} catch (Exception $e) {
			throw $e;
		}

		return $result;
	}

	/**
	 * Get given contact's email, first_name, last_name,
	 * city, state/province, country, post code
	 *
	 * @param int $id An existing CiviCRM contact id
	 *
	 * @return array Retrieved contact info
	 */
	private function getContactData($id) {
		$result = [];
		$participant_result = [];
		$iso_result = [];

		try {
			$participant_result = civicrm_api3('Contact', 'get', [
			  'sequential' => 1,
			  'return' => ["email", "first_name", "last_name", "street_address", "city", "state_province_name", "country", "postal_code"],
			  'id' => $id,
			])['values'][0];
		} catch (Exception $e) {
			watchdog(
			  'NCN-Civi-Zoom CiviRules Action (AddToZoom)',
			  'Something went wrong with getting contact data.',
			  array(),
			  WATCHDOG_INFO
			);
		}

		try {
            $iso_result = civicrm_api3('Country', 'get', [
                'sequential' => 1,
                'return' => ["iso_code"],
                'id' => $participant_result[country_id],
            ])['values'][0];

        }  catch (Exception $e) {
            watchdog(
                'NCN-Civi-Zoom CiviRules Action (AddToZoom)',
                'Something went wrong with getting the country code for the contact.',
                array(),
                WATCHDOG_INFO
            );
        }

        //Combine the fields for a result array in the order that the Zoom API needs
        //https://marketplace.zoom.us/docs/api-reference/zoom-api/meetings/meetingregistrantcreate

        $result = [
            'email' => $participant_result['email'],
            'first_name' => $participant_result['first_name'],
            'last_name' => $participant_result['last_name'],
            'street_address' => $participant_result['street_address'],
            'city' => $participant_result['city'],
            'country' => $iso_result['iso_code'],
            'postal_code' => $participant_result['postal_code']];
		return $result;
	}

	/**
	 * Add's the given participant data as a single participant
	 * to a Zoom Webinar/Meeting with the given id.
	 *
	 * @param array $participant participant data where email, first_name, and last_name are required - ISO Country Code is required for Webinars
	 * @param int $entityID id of an existing Zoom webinar/meeting
	 * @param string $entity 'Meeting' or 'Webinar'
	 */
	private function addParticipant($participant, $entityID, $triggerData, $entity) {
		$event = $triggerData->getEntityData('Event');
		$accountId = CRM_NcnCiviZoom_Utils::getZoomAccountIdByEventId($event['id']);
		$settings = CRM_NcnCiviZoom_Utils::getZoomSettings();
		if($entity == 'Webinar'){
			$url = $settings['base_url'] . "/webinars/$entityID/registrants";
		} elseif($entity == 'Meeting'){
			$url = $settings['base_url'] . "/meetings/$entityID/registrants";
		}
		$token = $this->createJWTToken($accountId);
		$response = Zttp::withHeaders([
			'Content-Type' => 'application/json;charset=UTF-8',
			'Authorization' => "Bearer $token"
		])->post($url, $participant);
		$result = $response->json();

		CRM_Core_Error::debug_var('Zoom addParticipant result', $result);
		if(!empty($result['join_url'])){
			$participantId = $triggerData->getEntityData('participant')['participant_id'];
			CRM_NcnCiviZoom_Utils::updateZoomParticipantJoinLink($participantId, $result['join_url']);
		}

		// Added the registrant_id and event_id to the log
		$msg = "Event Id is ".$event['id']. ". ";
		if(!empty($result['registrant_id'])){
			$msg .= "Registrant Id is ".$result['registrant_id']. ". ";
		}
		// Alert to user on success.
		if ($response->isOk()) {
			$firstName = $participant['first_name'];
			$lastName = $participant['last_name'];
			$msg .= 'Participant Added to Zoom. $entity ID: '.$entityID;
			$this->logAction($msg, $triggerData, \PSR\Log\LogLevel::INFO);

			CRM_Core_Session::setStatus(
				"$firstName $lastName was added to Zoom $entity $entityID.",
				ts('Participant added!'),
				'success'
			);
		} else {
			$msg .= $result['message'].' $entity ID: '.$entityID;
			$this->logAction($msg, $triggerData, \PSR\Log\LogLevel::ALERT);
		}
	}

	private function createJWTToken($id) {
		$settings = CRM_NcnCiviZoom_Utils::getZoomSettings($id);
		$key = $settings['secret_key'];
		$payload = array(
		    "iss" => $settings['api_key'],
		    "exp" => strtotime('+1 hour')
		);
		$jwt = JWT::encode($payload, $key);
		return $jwt;
	}

	public function getJoinUrl($object){
		$eventId = $object->event_id;
		$accountId = CRM_NcnCiviZoom_Utils::getZoomAccountIdByEventId($eventId);
		$settings = CRM_NcnCiviZoom_Utils::getZoomSettings();
		$webinar = $object->getWebinarID($eventId);
		$meeting = $object->getMeetingID($eventId);
		$url = '';
		$eventType = '';
	  if(!empty($meeting)){
	  	$url = $settings['base_url'] . "/meetings/".$meeting;
	  	$eventType = 'Meeting';
	  } elseif (!empty($webinar)) {
	  	$url = $settings['base_url'] . "/webinars/".$webinar;
	  	$eventType = 'Webinar';
	  } else {
	  	return [null, null, null];
	  }
	  $token = $object->createJWTToken($accountId);
		$response = Zttp::withHeaders([
			'Content-Type' => 'application/json;charset=UTF-8',
			'Authorization' => "Bearer $token"
		])->get($url);
		$result = $response->json();
		$joinUrl = $result['join_url'];
		$password = isset($result['password'])? $result['password'] : '';
		return [$joinUrl, $password, $eventType];
	}

	public static function checkEventWithZoom($params){
		if(empty($params) || empty($params["account_id"])
			|| empty($params["entityID"])
			|| empty($params["entity"])){
			return ['status' => null , 'message' => "Parameters missing"];
		}

		$object = new CRM_CivirulesActions_Participant_AddToZoom;
		$url = '';
		$settings = CRM_NcnCiviZoom_Utils::getZoomSettings($params["account_id"]);
		if($params["entity"] == 'Meeting'){
	  	$url = $settings['base_url'] . "/meetings/".$params["entityID"];
		} elseif ($params["entity"] == 'Webinar') {
	  	$url = $settings['base_url'] . "/webinars/".$params["entityID"];
		}

	  $token = $object->createJWTToken($params["account_id"]);

    //MV: Additional Check by user id if configured in settings
    $userID = CRM_Utils_Array::value('user_id', $settings);
    if (!empty($userID)) {
      // Does Meeting/Webinar id is belongs to given user ?. If not return validation error.
      $userParams = $params;
      $userParams['user_id'] = $userID;
      $userDetails = CRM_NcnCiviZoom_Utils::validateMeetingWebinarByUserId($userParams);

      // If we cannot find user details then return error
      if (empty($userDetails)) {
        return ["status" => 0, "message" => "Please verify the User ID"];
      }
      // else if user id exists and meeting/webinar not belong to this user then return.
      elseif (!empty($userDetails['message'])) {
        return ["status" => 0, "message" => $userDetails['message']];
      }
    }
    //END

		$response = Zttp::withHeaders([
			'Content-Type' => 'application/json;charset=UTF-8',
			'Authorization' => "Bearer $token"
		])->get($url);
		$result = $response->json();
		CRM_Core_Error::debug_var('checkEventWithZoom response', $result);
		$return = array(
			'status' => 0,
			'message' => 'Sorry, unable to verify.',
		);

		if($response->isOk()){
			if(!empty($result['registration_url'])){
				$return = array("status" => 1, "message" => $params["entity"]." has been verified");
			}else{
				$return = array("status" => 0, "message" => "Please enable the Registration as required for the Zoom ".$params["entity"].": ".$params["entityID"]);
			}
		} else {
			$return = array("status" => 0, "message" => $params["entity"]." does not belong to the ".$settings['name']);
		}

		// Check for additional fields enabled
		if($return['status']){
			if($params["entity"] == 'Meeting'){
		  	$url = $settings['base_url'] . "/meetings/".$params["entityID"]."/registrants/questions";
			} elseif ($params["entity"] == 'Webinar') {
		  	$url = $settings['base_url'] . "/webinars/".$params["entityID"]."/registrants/questions";
			}
			$response = Zttp::withHeaders([
				'Content-Type' => 'application/json;charset=UTF-8',
				'Authorization' => "Bearer $token"
			])->get($url);
			$result = $response->json();
			if($response->isOk()){
				// Checking for fields other than last_name
				foreach ($result['questions'] as $question) {
					if($question['field_name'] != 'last_name' && $question['required']){
						$return['status'] = -1;
						$return['message'] = $params["entity"]." has been verified. But participants may not be added to zoom as additional fields are marked as required in zoom.";
					}
				}
				// Checking for custom fields
				foreach ($result['custom_questions'] as $custom_question) {
					if($custom_question['required']){
						$return['status'] = -1;
						$return['message'] = $params["entity"]." has been verified. But participants may not be added to zoom as custom questions are marked as required in zoom.";
					}
				}
			}
		}

		return $return;
	}

  public static function getZoomRegistrants($eventId, $pageSize = 150){
    if(empty($eventId)){
      return [];
    }
    $object = new CRM_CivirulesActions_Participant_AddToZoom;
	  $webinarId = $object->getWebinarID($eventId);
	  $meetingId = $object->getMeetingID($eventId);
	  $zoomRegistrantsList = [];
	  if(empty($webinarId) && empty($meetingId)){
	  	return $zoomRegistrantsList;
	  }
		$url = '';
		$accountId = CRM_NcnCiviZoom_Utils::getZoomAccountIdByEventId($eventId);
		$settings = CRM_NcnCiviZoom_Utils::getZoomSettings();
		CRM_NcnCiviZoom_Utils::checkPageSize($pageSize);
		if(!empty($meetingId)){
	  	$url = $settings['base_url'] . "/meetings/".$meetingId.'/registrants?&page_size='.$pageSize;
		} elseif (!empty($webinarId)) {
	  	$url = $settings['base_url'] . "/webinars/".$webinarId.'/registrants?&page_size='.$pageSize;
		}
		$page = 1;
	  $token = $object->createJWTToken($accountId);
	  $result = [];
	  $next_page_token = null;
		do {
			$fetchUrl = $url.$next_page_token;
		  $token = $object->createJWTToken($accountId);
			$response = Zttp::withHeaders([
				'Content-Type' => 'application/json;charset=UTF-8',
				'Authorization' => "Bearer $token"
			])->get($fetchUrl);
			$result = $response->json();
			CRM_Core_Error::debug_var('getZoomRegistrants result', $result);
			if(!empty($result['registrants'])){
				$zoomRegistrantsList = array_merge($zoomRegistrantsList, $result['registrants']);
			}
			$next_page_token = '&next_page_token='.$result['next_page_token'];
		} while ($result['next_page_token']);

    return $zoomRegistrantsList;
  }

  public static function getZoomAttendeeOrAbsenteesList($eventId, $pageSize = 150){
    if(empty($eventId)){
      return [];
    }
    $object = new CRM_CivirulesActions_Participant_AddToZoom;
	  $webinarId = $object->getWebinarID($eventId);
	  $meetingId = $object->getMeetingID($eventId);
	  $returnZoomList = [];
	  if(empty($webinarId) && empty($meetingId)){
	  	return $returnZoomList;
	  }
		$url = $array_name = $key_name = '';
		$accountId = CRM_NcnCiviZoom_Utils::getZoomAccountIdByEventId($eventId);
		$settings = CRM_NcnCiviZoom_Utils::getZoomSettings();
		CRM_NcnCiviZoom_Utils::checkPageSize($pageSize);
		if(!empty($meetingId)){
	  	$url = $settings['base_url'] . "/past_meetings/$meetingId/participants?&page_size=".$pageSize;
	  	$array_name = 'participants';
	  	$key_name = 'user_email';
		} elseif (!empty($webinarId)) {
	  	$url = $settings['base_url'] . "/past_webinars/$webinarId/absentees?&page_size=".$pageSize;
	  	$array_name = 'absentees';
	  	$key_name = 'email';
		}
	  $token = $object->createJWTToken($accountId);
	  $result = [];
	  $next_page_token = null;
		do {
			$fetchUrl = $url.$next_page_token;
		  $token = $object->createJWTToken($accountId);
			$response = Zttp::withHeaders([
				'Content-Type' => 'application/json;charset=UTF-8',
				'Authorization' => "Bearer $token"
			])->get($fetchUrl);
			$result = $response->json();
			CRM_Core_Error::debug_var('zoom result', $result);
			if(!empty($result[$array_name])){
				$list = $result[$array_name];
				foreach ($list as $item) {
					$returnZoomList[] = $item[$key_name];
				}
			}
			$next_page_token = '&next_page_token='.$result['next_page_token'];
		} while ($result['next_page_token']);
    return $returnZoomList;
  }

  /**
   *
   * @param $eventId type-integer
   *
   * @return $returnZoomList type-array of zoom participants data
   */
  public static function getZoomParticipantsData($eventId, $pageSize = 150){
    if(empty($eventId)){
      return [];
    }
    $object = new CRM_CivirulesActions_Participant_AddToZoom;
	  $webinarId = $object->getWebinarID($eventId);
	  $meetingId = $object->getMeetingID($eventId);
	  $returnZoomList = [];
	  if(empty($webinarId) && empty($meetingId)){
	  	return $returnZoomList;
	  }
		$url = $array_name = $key_name = '';
		$accountId = CRM_NcnCiviZoom_Utils::getZoomAccountIdByEventId($eventId);
		$settings = CRM_NcnCiviZoom_Utils::getZoomSettings();
		CRM_NcnCiviZoom_Utils::checkPageSize($pageSize);
		if(!empty($meetingId)){
			// Calling Meeting participants report api
	  	$url = $settings['base_url'] . "/report/meetings/$meetingId/participants?&page_size=".$pageSize;
	  	$array_name = 'participants';
	  	$key_name = 'user_email';
		} elseif (!empty($webinarId)) {
			// Calling Webinar absentees api
	  	$url = $settings['base_url'] . "/past_webinars/$webinarId/absentees?&page_size=".$pageSize;
	  	$array_name = 'absentees';
	  	$key_name = 'email';
		}
	  $token = $object->createJWTToken($accountId);

	  $result = [];
	  $next_page_token = null;
		do {
			$fetchUrl = $url.$next_page_token;
		  $token = $object->createJWTToken($accountId);
			$response = Zttp::withHeaders([
				'Content-Type' => 'application/json;charset=UTF-8',
				'Authorization' => "Bearer $token"
			])->get($fetchUrl);
			$result = $response->json();
			CRM_Core_Error::debug_var('getZoomParticipantsData zoom result', $result);
			if(!empty($result[$array_name])){
				$list = $result[$array_name];
				foreach ($list as $item) {
					$returnZoomList[$item[$key_name]][] = $item;
				}
			}
			$next_page_token = '&next_page_token='.$result['next_page_token'];
		} while ($result['next_page_token']);

		if (!empty($webinarId)) {
			// Calling Webinar participants report api also
	  	$url = $settings['base_url'] . "/report/webinars/$webinarId/participants?&page_size=".$pageSize;
	  	$array_name = 'participants';
	  	$key_name = 'user_email';
		  $token = $object->createJWTToken($accountId);

		  $result = [];
		  $next_page_token = null;
			do {
				$fetchUrl = $url.$next_page_token;
			  $token = $object->createJWTToken($accountId);
				$response = Zttp::withHeaders([
					'Content-Type' => 'application/json;charset=UTF-8',
					'Authorization' => "Bearer $token"
				])->get($fetchUrl);
				$result = $response->json();
				CRM_Core_Error::debug_var('getZoomParticipantsData zoom result', $result);
				if(!empty($result[$array_name])){
					$list = $result[$array_name];
					foreach ($list as $item) {
						$returnZoomList[$item[$key_name]][] = $item;
					}
				}
				$next_page_token = '&next_page_token='.$result['next_page_token'];
			} while ($result['next_page_token']);
		}

    return $returnZoomList;
  }

	/**
	 * Method to return the url for additional form processing for action
	 * and return false if none is needed
	 *
	 * @param int $ruleActionId
	 * @return bool
	 * @access public
	 */
	public function getExtraDataInputUrl($ruleActionId) {
  		return FALSE;
	}

  // MV: Add Zttp call function
  public function requestZttpWithHeader($accountId, $url) {

    $object = new CRM_CivirulesActions_Participant_AddToZoom;
    $token  = $object->createJWTToken($accountId);
    $request = Zttp::withHeaders([
      'Content-Type' => 'application/json;charset=UTF-8',
      'Authorization' => "Bearer $token"
    ])->get($url);

    $isRequestOK = $request->isOk();
    $result = $request->json();

    return [$isRequestOK, $result];
  }
}

