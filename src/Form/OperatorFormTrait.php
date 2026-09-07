<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Form;

/**
 * Shared operator-form landmarks, help association and result focus targets.
 *
 * @internal
 */
trait OperatorFormTrait {

  /**
   * Applies the operator library, help landmark and described-by association.
   *
   * @param array $form
   *   Form render array.
   * @param string $help_id
   *   HTML id for the instructions container.
   * @param mixed $help
   *   Optional instruction markup. NULL leaves existing help in place.
   */
  protected function operatorShell(array &$form, string $help_id, mixed $help = NULL): void {
    $form['#cache']['max-age'] = 0;
    $form['#attributes']['class'][] = 'postmark-webhooks-operator';
    $form['#attached']['library'][] = 'postmark_webhooks/operator';
    if ($help === NULL) {
      return;
    }
    $form['#attributes']['aria-describedby'] = $help_id;
    $form['explanation'] = [
      '#type' => 'container',
      '#weight' => -100,
      '#attributes' => [
        'id' => $help_id,
        'class' => ['postmark-webhooks-operator__help'],
        'role' => 'note',
      ],
      'text' => ['#markup' => $help],
    ];
  }

  /**
   * Returns a named result region that keyboard focus can target.
   *
   * @param string $id
   *   HTML id for the region.
   * @param mixed $label
   *   Accessible name.
   *
   * @return array
   *   Render array for the region container.
   */
  protected function operatorResult(string $id, mixed $label): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => $id,
        'class' => ['postmark-webhooks-operator__result'],
        'role' => 'region',
        'aria-label' => $label,
        'tabindex' => '-1',
      ],
    ];
  }

}
