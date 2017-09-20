<?php

namespace Drupal\jcms_rest\Plugin\rest\resource;

use Drupal\jcms_rest\Exception\JCMSNotFoundHttpException;
use Drupal\jcms_rest\Response\JCMSRestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "job_advert_item_rest_resource",
 *   label = @Translation("Job advert item rest resource"),
 *   uri_paths = {
 *     "canonical" = "/job-adverts/{id}"
 *   }
 * )
 */
class JobAdvertItemRestResource extends AbstractRestResourceBase {
  /**
   * Responds to GET requests.
   *
   * Returns a list of bundles for specified entity.
   *
   * @param string $id
   * @return array|\Symfony\Component\HttpFoundation\JsonResponse
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function get($id) {
    $query = \Drupal::entityQuery('node')
      ->condition('status', \Drupal\node\NodeInterface::PUBLISHED)
      ->condition('changed', \Drupal::time()->getRequestTime(), '<')
      ->condition('type', 'job_advert')
      ->condition('uuid', '%' . $id, 'LIKE');

    $nids = $query->execute();
    if ($nids) {
      $nid = reset($nids);
      /* @var \Drupal\node\Entity\Node $node */
      $node = \Drupal\node\Entity\Node::load($nid);

      $this->setSortBy(FALSE);
      $response = $this->processDefault($node, $id);

      // Impact statement is optional.
      if ($node->get('field_impact_statement')->count()) {
        $response['impactStatement'] = $this->fieldValueFormatted($node->get('field_impact_statement'));
        if (empty($response['impactStatement'])) {
          unset($response['impactStatement']);
        }
      }

      $response['content'] = $this->deriveContentJson($node);

      $response = new JCMSRestResponse($response, Response::HTTP_OK, ['Content-Type' => $this->getContentType()]);
      $response->addCacheableDependency($node);
      return $response;
    }

    throw new JCMSNotFoundHttpException(t('Job advert with ID @id was not found', ['@id' => $id]));
  }

  /**
   * @param \Drupal\node\Entity\Node $node
   * @return array
   */
  private function deriveContentJson($node) {
    $contentJson = [];
    $summaryParas = $this->getFieldJson($node, 'field_job_advert_role_summary');
    foreach ($summaryParas as $para) {
      array_push($contentJson, $para);
    }
    $sectionFieldNames = [
      'field_job_advert_experience',
      'field_job_advert_respons',
      'field_job_advert_terms',
    ];
    foreach($sectionFieldNames as $fieldName) {
      array_push($contentJson, $this->getFieldJson($node, $fieldName));
    }

    forEach ($contentJson as $i => $item) {
      if (empty($item)) {
        unset($contentJson[$i]);
      }
    }

    return $contentJson;
  }

  /**
   * @param \Drupal\node\Entity\Node $node
   * @param string $fieldName
   * @return array
   */
  private function getFieldJson($node, $fieldName) {

    $field = $node->get($fieldName);
    $isSection = false;
    if ($field->count()) {
      $texts = $this->splitParagraphs($this->fieldValueFormatted($field, FALSE));
      foreach ($texts as $i => $text) {
        if (is_array($text)) {
          $isSection = true;
        }
      }

      if ($isSection) {
        return $this->getFieldJsonAForSection($node->{$fieldName}->getFieldDefinition()->getLabel(), $texts);
      }

      return $this->getFieldJsonAsParagraphs($texts);
    }
  }

  private function getFieldJsonAForSection($title, $content) {
    return [
      'type' => 'section',
      'title' => $title,
      'content' => $content,
    ];
  }

  private function getFieldJsonAsParagraphs($paras) {
    foreach ($paras as $i => $para) {
      $paras[$i] = [
        'type' => 'paragraph',
        'text' => trim($para),
      ];
    }
    return $paras;
  }

}
