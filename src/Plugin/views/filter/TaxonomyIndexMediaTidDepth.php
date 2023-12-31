<?php

namespace Drupal\media_taxonomy_filter\Plugin\views\filter;

use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Plugin\views\filter\TaxonomyIndexTid;


/**
 * Filter handler for taxonomy terms with depth.
 *
 * This handler is actually part of the node table and has some restrictions,
 * because it uses a subquery to find nodes with.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("taxonomy_index_tid_media_depth")
 */
class TaxonomyIndexMediaTidDepth extends TaxonomyIndexTid {

  public function operatorOptions($which = 'title') {
    return [
      'or' => $this->t('Is one of'),
    ];
  }

  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['depth'] = ['default' => 0];

    return $options;
  }

  public function buildExtraOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildExtraOptionsForm($form, $form_state);

    $form['reference_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reference field'),
      '#default_value' => $this->options['reference_field'],
      '#required' => TRUE,
      '#description' => $this->t('The field name (machine name) in the media entity type, which is referencing to a taxonomy. For example field_media_category.'),
    ];

    $form['depth'] = [
      '#type' => 'weight',
      '#title' => $this->t('Depth'),
      '#default_value' => $this->options['depth'],
      '#description' => $this->t('The depth will match media entities tagged with terms in the hierarchy. For example, if you have the term "fruit" and a child term "apple", with a depth of 1 (or higher) then filtering for the term "fruit" will get media entities that are tagged with "apple" as well as "fruit". If negative, the reverse is true; searching for "apple" will also pick up media entities tagged with "fruit" if depth is -1 (or lower).'),
    ];
  }

  public function query() {

    // Get the DB table and reference column name from the reference field name.
    $refFieldName = $this->options['reference_field'] . '_target_id';
    $refTableName = 'media__' . $this->options['reference_field'];
    // If no filter values are present, then do nothing.
    if (count($this->value) == 0) {
      return;
    }
    elseif (count($this->value) == 1) {
      // Sometimes $this->value is an array with a single element so convert it.
      if (is_array($this->value)) {
        $this->value = current($this->value);
      }
      $operator = '=';
    }
    else {
      $operator = 'IN';
    }

    // The normal use of ensureMyTable() here breaks Views.
    // So instead we trick the filter into using the alias of the base table.
    //   See https://www.drupal.org/node/271833.
    // If a relationship is set, we must use the alias it provides.
    if (!empty($this->relationship)) {
      $this->tableAlias = $this->relationship;
    }
    // If no relationship, then use the alias of the base table.
    else {
      $this->tableAlias = $this->query->ensureTable($this->view->storage->get('base_table'));
    }

    // Now build the subqueries.
    $subquery = db_select($refTableName, 'tn');
    $subquery->addField('tn', 'entity_id');
    $where = (new Condition('OR'))->condition('tn.'. $refFieldName, $this->value, $operator);
    $last = "tn";

    if ($this->options['depth'] > 0) {
      $subquery->leftJoin('taxonomy_term_hierarchy', 'th', "th.tid = tn." . $refFieldName);
      $last = "th";
      foreach (range(1, abs($this->options['depth'])) as $count) {
        $subquery->leftJoin('taxonomy_term_hierarchy', "th$count", "$last.parent = th$count.tid");
        $where->condition("th$count.tid", $this->value, $operator);
        $last = "th$count";
      }
    }
    elseif ($this->options['depth'] < 0) {
      foreach (range(1, abs($this->options['depth'])) as $count) {
        $subquery->leftJoin('taxonomy_term_hierarchy', "th$count", "$last.tid = th$count.parent");
        $where->condition("th$count.tid", $this->value, $operator);
        $last = "th$count";
      }
    }

    $subquery->condition($where);
    $this->query->addWhere($this->options['group'], "$this->tableAlias.$this->realField", $subquery, 'IN');
  }

}