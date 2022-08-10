<?php

namespace Drupal\indicia_advanced_reports;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;

if (!defined('INDICIA_ID_FIELD')) {
  define('INDICIA_ID_FIELD', 'field_indicia_user_id');
}

/**
 * Prints to log and returns a json formatted error back to the client.
 *
 * @param int $code
 *   Status code of the header.
 * @param string $status
 *   Status of the header.
 * @param string $title
 *   Title of the error.
 * @param array|null $errors
 *   If multiple errors then it can be passed as an array.
 */
function error($code, $status, $title, $errors = NULL) {
  $headers = [
    'Status' => $code . ' ' . $status,
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Methods' => 'GET,PUT,OPTIONS',
    'Access-Control-Allow-Headers' => 'authorization, x-api-key',
  ];
  if (is_null($errors)) {
    $data = [
      'errors' => [
        [
          'status' => (string) $code,
          'title' => $title,
        ],
      ],
    ];
  }
  else {
    $data = [
      'errors' => $errors,
    ];
  }
  return new JsonResponse($data, $code, $headers);
}

/**
 * Advanced Report controller.
 *
 * Provides report outputs that are mash-ups, Elasticsearch based, or more
 * complex than simple reporting services calls.
 */
class AdvancedReportController extends ControllerBase {

  /**
   * GET method handler for advanced reports.
   *
   * @param string $report
   *   Name of the report (from the URL path).
   *
   * @return Symfony\Component\HttpFoundation\JsonResponse
   *   Report response or error response.
   */
  public function get($report) {
    $validReports = ['user-stats', 'counts', 'recorded-taxa-list'];
    if (empty($report) || !in_array($report, $validReports)) {
      return error(400, 'Bad Request', 'Missing or incorrect report url.');
    }

    $user = User::load($this->currentUser()->id());
    $userId = $user->get(INDICIA_ID_FIELD)->value;

    if ($filterError = $this->validateFilterParameters($report, $userId)) {
      return $filterError;
    }
    $filters = $this->getFilters($report);

    require_once 'RecorderMetrics.php';

    $rm = new \RecorderMetrics($userId);

    // Call report code.
    switch ($report) {
      case 'user-stats':
        $output = $rm->getUserMetrics($filters);
        break;

      case 'counts':
        // Count categories defaults to just records.
        if (!isset($_GET['categories'])) {
          $categories = ['records'];
        }
        else {
          $categories = explode(',', $_GET['categories']);
          foreach ($categories as $category) {
            $validCategories = ['records', 'species', 'photos', 'recorders'];
            if (!in_array($category, $validCategories)) {
              return error(
                400,
                'Bad Request',
                "Parameter for categories contains invalid value $category."
              );
            }
          }
        }
        $output = $rm->getCounts($filters, $categories);
        break;

      case 'recorded-taxa-list':
        // @deprecated exclude_higher_taxa parameter - use species_only instead.
        $excludeHigherTaxa =
          isset($_GET['exclude_higher_taxa']) &&
          $_GET['exclude_higher_taxa'] === 't'
            ? TRUE
            : FALSE;
        $speciesOnly =
          isset($_GET['species_only']) && $_GET['species_only'] === 't'
            ? TRUE
            : FALSE;
        $output = $rm->getRecordedTaxaList(
          $filters,
          $excludeHigherTaxa || $speciesOnly
        );
        break;

      default:
        return error(400, 'Bad Request', 'Unknown advanced report requested.');
    }
    $headers = [
      'Status' => '200 OK',
      'Access-Control-Allow-Origin' => '*',
      'Access-Control-Allow-Methods' => 'GET,PUT,OPTIONS',
      'Access-Control-Allow-Headers' => 'authorization, x-api-key, content-type',
    ];
    return new JsonResponse($output, '200', $headers);
  }

  /**
   * Checks query parameters are valid for the report.
   *
   * * All reports must filter by survey_id or group_id.
   * * If filtering by user_id, it must be for the logged in user.
   *
   * @param string $report
   *   Report name.
   * @param int $userId
   *   Warehouse user ID to filter reports for.
   *
   * @return Symfony\Component\HttpFoundation\JsonResponse
   *   Error response or NULL.
   */
  private function validateFilterParameters($report, $userId) {
    // All requests must be for a survey or a group/activity.
    if (empty($_GET['survey_id']) && empty($_GET['group_id'])) {
      // @todo Add support for website_id filter, but need to check error
      // handling if species list huge.
      return error(
        400,
        'Bad Request',
        'Parameter for survey_id or group_id missing from query string.'
      );
    }
    // Some have optional user filter.
    if (
      !empty($_GET['user_id']) &&
      $_GET['user_id'] !== $userId &&
      $report !== 'user-stats'
    ) {
      // Can only request your own data.
      return error(401, 'Unauthorized', "Cannot request other user's data.");
    }
    return NULL;
  }

  /**
   * Returns the filters to apply.
   *
   * @param string $report
   *   Report name.
   *
   * @return array
   *   Filters in the form of key/value pairs where the keys are ES document
   *   field names.
   */
  private function getFilters($report) {
    $filters = [];
    if (!empty($_GET['survey_id'])) {
      $filters['metadata.survey.id'] = $_GET['survey_id'];
    }
    elseif (!empty($_GET['group_id'])) {
      $filters['metadata.group.id'] = $_GET['group_id'];
    }
    // Optional taxon group filter.
    if (!empty($_GET['taxon_group_id'])) {
      $filters['taxon.group_id'] = $_GET['taxon_group_id'];
    }
    // Some end-points can return data for any chosen year.
    if (!empty($_GET['year']) && $report !== 'user-stats') {
      $filters['event.year'] = $_GET['year'];
    }
    // Apply user filter where appropriate.
    if (!empty($_GET['user_id']) && $report !== 'user-stats') {
      $filters['metadata.created_by_id'] = $_GET['user_id'];
    }
    return $filters;
  }

}
