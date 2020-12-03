<?php

namespace Drupal\csvimport\Form;

use Drupal\Component\Utility\Environment;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;

/**
 * Implements form to upload a file and start the batch on form submit.
 *
 * @see \Drupal\Core\Form\FormBase
 * @see \Drupal\Core\Form\ConfigFormBase
 */
class CsvImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'csvimport_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, GroupInterface $group = null) {



    $form['#attributes'] = [
      'enctype' => 'multipart/form-data',
    ];

    $form['csvfile'] = [
      '#title'            => $this->t('CSV File'),
      '#type'             => 'file',
      '#required'	  => TRUE,
      '#description'      => ($max_size = Environment::getUploadMaxSize()) ? $this->t('Due to server restrictions, the <strong>maximum upload file size is @max_size</strong>. Files that exceed this size will be disregarded.', ['@max_size' => format_size($max_size)]) : '',
      '#element_validate' => ['::validateFileupload'],
    ];

    $options = array(
      1 => ';',
      2 => ','
    );

    $form['delimiter'] = array(
      '#type' => 'select',
      '#options' => $options,
      '#title' => $this->t('Delimiter')
    );

    $form['group'] = array(
      '#type' => 'entity_autocomplete',
      '#target_type' => 'group',
      '#default_value' => $group, // The #default_value can be either an entity object or an array of entity objects.
      '#disabled' => TRUE
    );

    $form['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Start Import'),
    ];

    $form['help'] = array(
      '#type' => 'details',
      '#open' => TRUE // Controls the HTML5 'open' attribute. Defaults to FALSE.
    );

   /* $help_content = [
      '#theme' => 'item_list',
      '#list_type' => 'ol',
      '#title' => t('Required field names and ordering!'),
      '#items' => ['email | valid email-address',
                   'status | 1, 0 (1 = active, 0 = blocked)',
                   'pass | provide a standard password for the user as a fallback option (does not overwrite the password of existing users)',
                   'field_profile_first_name | provide the first name',
                   'field_profile_last_name | provide the last name',
                   'field_profile_organization | provide the organisation name',
                   'langcode | en, fr, es, bs (en = English, es = Spanish, fr = French, bs = Bosnian)',
                   'timezone | Africa/Abidjan, Europe/Prague etc. (compare https://en.wikipedia.org/wiki/List_of_tz_database_time_zones)'
                    ],
      '#attributes' => ['class' => 'help-csvimport'],
      '#wrapper_attributes' => ['class' => 'container'],
    ];

    $form['help']['#markup'] = render($help_content);*/

    return $form;

  }

  /**
   * Validate the file upload.
   */
  public static function validateFileupload(&$element, FormStateInterface $form_state, &$complete_form) {

    $validators = [
      'file_validate_extensions' => ['csv'],
    ];

    // @TODO: File_save_upload will probably be deprecated soon as well.
    // @see https://www.drupal.org/node/2244513.
    if ($file = file_save_upload('csvfile', $validators, FALSE, 0, FILE_EXISTS_REPLACE)) {

      // The file was saved using file_save_upload() and was added to the
      // files table as a temporary file. We'll make a copy and let the
      // garbage collector delete the original upload.
      $csv_dir = 'temporary://csvfile';
      $directory_exists = \Drupal::service('file_system')
        ->prepareDirectory($csv_dir, FileSystemInterface::CREATE_DIRECTORY);

      if ($directory_exists) {
        $destination = $csv_dir . '/' . $file->getFilename();
        if (file_copy($file, $destination, FileSystemInterface::EXISTS_REPLACE)) {
          $form_state->setValue('csvupload', $destination);
        }
        else {
          $form_state->setErrorByName('csvimport', t('Unable to copy upload file to @dest', ['@dest' => $destination]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    $delimiter = $form_state->getValue('delimiter');

    if ($delimiter == 1) {
      $delimiter = ';';
    }
    else {
      $delimiter = ',';
    }

    if ($csvupload = $form_state->getValue('csvupload')) {

      ini_set('auto_detect_line_endings', true);

      if ($handle = fopen($csvupload, 'r')) {

        if ($line = fgetcsv($handle, 4096, $delimiter)) {

          if ($line[0] != 'email' && $line[1] != 'status' && $line[2] != 'pass' && $line[3] != 'field_profile_first_name' &&  
            $line[4] != 'field_profile_last_name' || $line[5] != 'field_profile_organization' || $line[6] != 'langcode' || $line[7] != 'timezone' ) {
            $form_state->setErrorByName('csvfile', $this->t('Sorry, this file does not match the expected format.'));
          }

        }
        fclose($handle);
      }
      else {
        $form_state->setErrorByName('csvfile', $this->t('Unable to read uploaded file @filepath', ['@filepath' => $csvupload]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $group = $form_state->getValue('group');
    $delimiter = $form_state->getValue('delimiter');

    if ($delimiter == 1) {
      $delimiter = ';';
    }
    else {
      $delimiter = ',';
    }


    $batch = [
      'title'            => $this->t('Importing CSV ...'),
      'operations'       => [],
      'init_message'     => $this->t('Commencing'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message'    => $this->t('An error occurred during processing'),
      'finished'         => '\Drupal\csvimport\Batch\CsvImportBatch::csvimportImportFinished'
    ];

    if ($csvupload = $form_state->getValue('csvupload')) {

      if ($handle = fopen($csvupload, 'r')) {

        $batch['operations'][] = [
          '\Drupal\csvimport\Batch\CsvImportBatch::csvimportRememberFilename',
          [$csvupload]
        ];

        while ($line = fgetcsv($handle, 4096, $delimiter)) {

          // Use base64_encode to ensure we don't overload the batch
          // processor by stuffing complex objects into it.
          $batch['operations'][] = [
            '\Drupal\csvimport\Batch\CsvImportBatch::csvimportImportLine',
            [array_map('base64_encode', $line),$group]
          ];
        }

        fclose($handle);
      }
    }

    batch_set($batch);
  }


    /**
   * Checks access for a specific request.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, GroupInterface $group = NULL) {
    // Check permissions and combine that with any custom access checking needed. Pass forward
    // parameters from the route and/or request as needed.

    $user = User::load($account->id());

    if ($group) {

      $member = $group->getMember($account);

      if ($member) {
        if($member->hasPermission('edit group', $account)) {
          return AccessResult::allowed();
        }
      }
      elseif ($user->hasRole('administrator')) {
        return AccessResult::allowed();
      }

    }

    return AccessResult::forbidden();

  }


}
