<?php

namespace Drupal\csvimport\Batch;

// @codingStandardsIgnoreStart
// Node can be used later to actually create nodes. See commented code block
// in csvimportImportLine() below. Since it's unused right now, we hide it from
// coding standards linting.
use Drupal\Core\File\FileSystemInterface;
use Drupal\user\Entity\User;
use Drupal\user\Entity\UserInterface;
use Drupal\profile\Entity\Profile;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;


// @codingStandardsIgnoreEnd

/**
 * Methods for running the CSV import in a batch.
 *
 * @package Drupal\csvimport
 */
class CsvImportBatch {


  /**
   * Handle batch completion.
   *
   *   Creates a new CSV file containing all failed rows if any.
   */
  public static function csvimportImportFinished($success, $results, $operations) {

    $messenger = \Drupal::messenger();

    \Drupal::logger('social_welcome_message')->notice('<pre><code>' . print_r($results, TRUE) . '</code></pre>');

    if (!empty($results['failed_rows'])) {

      $dir = 'public://csvimport';
      if (\Drupal::service('file_system')
        ->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY)) {

        // We validated extension on upload.
        $csv_filename = 'failed_rows-' . basename($results['uploaded_filename']);
        $csv_filepath = $dir . '/' . $csv_filename;

        $targs = [
          ':csv_url'      => file_create_url($csv_filepath),
          '@csv_filename' => $csv_filename,
          '@csv_filepath' => $csv_filepath,
        ];

        ini_set('auto_detect_line_endings', true);

        if ($handle = fopen($csv_filepath, 'w+')) {

          foreach ($results['failed_rows'] as $failed_row) {
            fputcsv($handle, $failed_row);
          }

          fclose($handle);
          $messenger->addMessage(t('Some rows failed to import. You may download a CSV of these rows: <a href=":csv_url">@csv_filename</a>', $targs), 'error');
        }
        else {
          $messenger->addMessage(t('Some rows failed to import, but unable to write error CSV to @csv_filepath', $targs), 'error');
        }
      }
      else {
        $messenger->addMessage(t('Some rows failed to import, but unable to create directory for error CSV at @csv_directory', $targs), 'error');
      }
    }

    if ($success) {
      $message = t('Import completed!');
      // Here we do something meaningful with the results.
      //$message = t("@count tasks were done.", array(
        //'@count' => count($results),
      //));
      \Drupal::messenger()->addMessage($message);
    }

    if (!empty($results['group'])) {
      $redirect_link = '/group/' . $results['group'] . '/membership';
      return new RedirectResponse($redirect_link);
    }


  }

  /**
   * Remember the uploaded CSV filename.
   *
   * @TODO Is there a better way to pass a value from inception of the batch to
   * the finished function?
   */
  public static function csvimportRememberFilename($filename, &$context) {

    $context['results']['uploaded_filename'] = $filename;
  }


  /**
   * Process a single line.
   */
  public static function csvimportImportLine($line, $group, &$context) {

    $context['results']['rows_imported']++;
    $line = array_map('base64_decode', $line);

    $context['results']['group'] = $group;

    // Simply show the import row count.
    $context['message'] = t('Importing row !c', ['!c' => $context['results']['rows_imported']]);

    // Alternatively, our example CSV happens to have the title in the
    // third column, so we can uncomment this line to display "Importing
    // Blahblah" as each row is parsed.
    //
    // You can comment out the line above if you uncomment this one.
    $context['message'] = t('Importing %title', ['%title' => $line[2]]);

    // In order to slow importing and debug better, we can uncomment
    // this line to make each import slightly slower.
    // @codingStandardsIgnoreStart
    //usleep(2500);

    // @codingStandardsIgnoreEnd
    // Convert the line of the CSV file into a new node.
    // @codingStandardsIgnoreStart
    if ($context['results']['rows_imported'] > 1) { // Skip header line.

      // Remove any whitespace
      $email = str_replace(' ', '', $line[0]);
      // Remove whitespaces
      $status = trim($line[1]);
      $pass = trim($line[2]);
      $field_profile_first_name = trim($line[3]);
      $field_profile_last_name = trim($line[4]);
      $field_profile_organization = trim($line[5]);
      $langcode = str_replace(' ', '', $line[6]);
      $timezone = str_replace(' ', '', $line[7]);

      if ($email) {
        if (!CsvImportBatch::validateEmail($email)) {
          $context['results']['failed_rows'][] = $line;
        }
      }



      $user_status = user_load_by_mail($email);

      if ($user_status == FALSE) {


        if (!CsvImportBatch::validateStatus($status)) {
          $status = 0;
        }
        else {
          $status = 1;
        }

        // Default language
        $language_default = \Drupal::languageManager()->getCurrentLanguage()->getId();
        $config = \Drupal::config('system.date');
        $timezone_default =  $config->get('timezone.default');

        // Default Timezone

        /* @var \Drupal\user\Entity\UserInterface $user */
        $user = User::create();
        $user->uid = '';
        $username = CsvImportBatch::getUserNameFromEmail($email);
        $user->setUsername($username);
        $user->setEmail($email);
        $user->set("init", $email);
        $user->set("status", $status);
        $user->setPassword(CsvImportBatch::cleanPassword($pass));


        if (!CsvImportBatch::isValidTimezone($timezone)) {
          $timezone = $timezone_default;
        }

        $user->set("timezone", $timezone);

        if (!CsvImportBatch::isValidLangcode($langcode)) {
          $langcode = $language_default;
        }

        $user->set("langcode", $langcode);
        $user->set("preferred_langcode", $langcode);
        $user->set("preferred_admin_langcode", $langcode);

        $user->enforceIsNew();
        $user->activate();
        $user->save();
        $uid = $user->id();

        \Drupal::logger('csvimport')->notice($group);


        $current_user = user_load($uid);

        // The Profile gets attached after a user has been saved
        // So make sure the active profile gets populated

        $active_profile = \Drupal::entityTypeManager()->getStorage('profile')->loadByUser($current_user, 'profile');

        if ($active_profile) {
          $active_profile->field_profile_first_name->value = $field_profile_first_name;
          $active_profile->field_profile_last_name->value = $field_profile_last_name;
          $active_profile->field_profile_organization->value = $field_profile_organization;
          $active_profile->save();
        }

        if($group) {
          $group_storage = \Drupal::entityTypeManager()->getStorage('group')->load($group);
          $group_storage->addMember($current_user);
        }

        \Drupal::logger('social_welcome_message')->notice('<pre><code>' . print_r($context, TRUE) . '</code></pre>');

              /*

        $profile = Profile::create([
          'type' => 'profile',
          'uid' => $uid,
          'field_profile_first_name' => $field_profile_first_name,
          'field_profile_last_name' => $field_profile_last_name,
          'field_profile_organisation' => $field_profile_organization,
        ]);

        $profile->setDefault(TRUE);
        $profile->save();

        */

      }
      else {

        // Update existing User
        $user = $user_status;



        // Set the status only if valid
        if (CsvImportBatch::validateStatus($status)) {
          \Drupal::logger('social_welcome_message')->notice($status);
          $user->set("status", $status);
        }
        else {
          \Drupal::logger('social_welcome_message')->notice('No status here');
        }

        if (CsvImportBatch::isValidTimezone($timezone)) {
          $user->set("timezone", $timezone);
        }        

        if (CsvImportBatch::isValidLangcode($langcode)) {
          $user->set("langcode", $langcode);
          $user->set("preferred_langcode", $langcode);
          $user->set("preferred_admin_langcode", $langcode);
        }

        // The Profile gets attached after a user has been saved
        // So make sure the active profile gets populated

        $active_profile = \Drupal::entityTypeManager()->getStorage('profile')->loadByUser($user, 'profile');

        if ($active_profile) {

          if ($field_profile_first_name) {
            $active_profile->field_profile_first_name->value = $field_profile_first_name;
          }

          if ($field_profile_last_name) {
            $active_profile->field_profile_last_name->value = $field_profile_last_name;
          }

          if ($field_profile_organization) {
            $active_profile->field_profile_organization->value = $field_profile_organization;
          }
        
          $active_profile->save();

        }

        if($group) {
          $group_storage = \Drupal::entityTypeManager()->getStorage('group')->load($group);
          $group_storage->addMember($user);
        }

        $user->save();

      }
    }
  }

  public static function isValidLangcode($langcode) {

    $allowed_languages = \Drupal::languageManager()->getLanguages();

    if(array_key_exists($langcode,$allowed_languages)) {
      return true;
    }

    return false;

  }

  public static function isValidTimezone($timezone) {

    $timezones = User::getAllowedTimezones();   

    if (in_array($timezone, $timezones)) {
      return true;
    }

    return false;

  }

  public static function getUserNameFromEmail(string $email) {
    $array = explode("@", $email);
    $username = $array[0];

    if (user_load_by_name($username)) {
      $random_number = random_int(2,6);
      $username = $username . '_' . $random_number;
    }

    return $username;
  }

  public static function validateEmail(string $email) {
    if (\Drupal::service('email.validator')->isValid($email)) {
      return true;
    }
    return false;
  }

  public static function validateStatus(string $status) {
    if ($status === "1" || $status === "0") {
      return true;
    }
    return false;
  }

  public static function cleanPassword(string $password) {
    return str_replace(' ','', $password);
  }

}
