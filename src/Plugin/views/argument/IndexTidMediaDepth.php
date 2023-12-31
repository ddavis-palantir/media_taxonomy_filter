<?php

namespace Drupal\media_taxonomy_filter\Plugin\views\argument;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Plugin\views\argument\IndexTidDepth;

/**
 * Argument handler for media entities with taxonomy terms with depth.
 *
 * Normally taxonomy terms with depth contextual filter can be used
 * only for content. This handler can be used for Drupal media entities.
 *
 * Handler expects reference field name, gets reference table and column and
 * builds sub query on that table. That is why handler does not need special
 * relation table like taxonomy_index.
 *
 * @ingroup views_argument_handlers
 *
 * @ViewsArgument("taxonomy_index_tid_media_depth")
 */
class IndexTidMediaDepth extends IndexTidDepth {

  /**
   * @var EntityStorageInterface
   */
  protected $termStorage;

  /**
   * Extend options.
   *
   * @return array
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['reference_field'] = ['default' => ''];
    return $options;
  }


  /**
   * @inheritdoc
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $form['reference_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reference field'),
      '#required' => TRUE,
      '#default_value' =>  $this->options['reference_field'],
      '#description' => $this->t('The field name (machine name) in the media entity type, which is referencing to a taxonomy. For example field_media_category.'),
    ];

    $form['depth'] = [
      '#type' => 'weight',
      '#title' => $this->t('Depth'),
      '#default_value' => $this->options['depth'],
      '#description' => $this->t('The depth will match media entities tagged with terms in the hierarchy. For example, if you have the term "fruit" and a child term "apple", with a depth of 1 (or higher) then filtering for the term "fruit" will get media entities that are tagged with "apple" as well as "fruit". If negative, the reverse is true; searching for "apple" will also pick up media entities tagged with "fruit" if depth is -1 (or lower).'),
    ];

    $form['break_phrase'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow multiple values'),
      '#description' => $this->t('If selected, users can enter multiple values in the form of 1+2+3. Due to the number of JOINs it would require, AND will be treated as OR with this filter.'),
      '#default_value' => !empty($this->options['break_phrase']),
    ];

    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * @inheritdoc
   */
  public function query($group_by = FALSE) {
    // Get the DB table and reference column name from the reference field name.
    $refFieldName = $this->options['reference_field'] . '_target_id';
    $refTableName = 'media__' . $this->options['reference_field'];

    $this->ensureMyTable();

    if (!empty($this->options['break_phrase'])) {
      $break = static::breakString($this->argument);
      if ($break->value === [-1]) {
        return FALSE;
      }

      $operator = (count($break->value) > 1) ? 'IN' : '=';
      $tids = $break->value;
    }
    else {
      $operator = "=";
      $tids = $this->argument;
    }

    // Now build the subqueries.
    $subquery = db_select($refTableName, 'pt');
    $subquery->addField('pt', 'entity_id');
    $where = db_or()->condition('pt.' . $refFieldName, $tids, $operator);
    $last = "pt";

    if ($this->options['depth'] > 0) {
      $subquery->leftJoin('taxonomy_term_hierarchy', 'th', "th.tid = pt." . $refFieldName);
      $last = "th";
      foreach (range(1, abs($this->options['depth'])) as $count) {
        $subquery->leftJoin('taxonomy_term_hierarchy', "th$count", "$last.parent = th$count.tid");
        $where->condition("th$count.tid", $tids, $operator);
        $last = "th$count";
      }
    }
    elseif ($this->options['depth'] < 0) {
      foreach (range(1, abs($this->options['depth'])) as $count) {
        $subquery->leftJoin('taxonomy_term_hierarchy', "th$count", "$last.tid = th$count.parent");
        $where->condition("th$count.tid", $tids, $operator);
        $last = "th$count";
      }
    }

    $subquery->condition($where);
    $this->query->addWhere(0, "$this->tableAlias.$this->realField", $subquery, 'IN');
  }

  /**
   * @inheritdoc
   */
  public function title() {
    $term = $this->termStorage->load($this->argument);
    if (!empty($term)) {
      $title = $term->getName();
      return $title;
    }
    return $this->t('No name');
  }

}