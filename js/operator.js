/**
 * @file
 * Moves keyboard focus to operator result regions after a rebuild.
 */
((Drupal, once) => {
  Drupal.behaviors.postmarkWebhooksOperatorResult = {
    attach(context) {
      once(
        'postmark-webhooks-operator-result',
        '.postmark-webhooks-operator__result',
        context,
      ).forEach((element) => {
        element.focus();
      });
    },
  };
})(Drupal, once);
