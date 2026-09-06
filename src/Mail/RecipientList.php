<?php

namespace Drupal\postmark_webhooks\Mail;

use Drupal\Component\Utility\EmailValidator;

/**
 * Parses recipient lists without splitting quoted names or mailbox local parts.
 */
final class RecipientList {

  /**
   * Returns distinct normalized addresses; malformed lists fail as a whole.
   */
  public static function parse(mixed $value): array {
    if (is_array($value)) {
      $addresses = [];
      foreach ($value as $item) {
        if (!is_string($item)) {
          throw new \InvalidArgumentException('Invalid recipient list item.');
        }
        $addresses = array_merge($addresses, self::parse($item));
      }
      return array_values(array_unique($addresses));
    }
    if (!is_string($value) || preg_match('/[\r\n\x00]/', $value)) {
      throw new \InvalidArgumentException('Invalid recipient list.');
    }
    if (trim($value) === '') {
      return [];
    }
    $tokens = [];
    $token = '';
    $quoted = FALSE;
    $escaped = FALSE;
    $comment = 0;
    $angle = FALSE;
    for ($i = 0; $i < strlen($value); $i++) {
      $char = $value[$i];
      if ($escaped) {
        if (!$comment) {
          $token .= $char;
        }
        $escaped = FALSE;
        continue;
      }
      if ($char === '\\' && ($quoted || $comment)) {
        $escaped = TRUE;
        if (!$comment) {
          $token .= $char;
        }
        continue;
      }
      if (!$quoted && $char === '(') {
        $comment++;
        continue;
      }
      if ($comment) {
        if ($char === ')') {
          $comment--;
        }
        continue;
      }
      if ($char === '"') {
        $quoted = !$quoted;
      }
      if (!$quoted) {
        if ($char === '<') {
          if ($angle) {
            throw new \InvalidArgumentException('Nested mailbox brackets.');
          }
          $angle = TRUE;
        }
        elseif ($char === '>') {
          if (!$angle) {
            throw new \InvalidArgumentException('Unmatched mailbox bracket.');
          }
          $angle = FALSE;
        }
        elseif ($char === ':' || $char === ';' || $char === ')') {
          throw new \InvalidArgumentException('Unsupported recipient syntax.');
        }
        elseif ($char === ',' && !$angle) {
          $tokens[] = $token;
          $token = '';
          continue;
        }
      }
      $token .= $char;
    }
    if ($quoted || $escaped || $comment || $angle) {
      throw new \InvalidArgumentException('Unterminated recipient syntax.');
    }
    $tokens[] = $token;
    $addresses = [];
    $validator = new EmailValidator();
    foreach ($tokens as $token) {
      $token = trim($token);
      if (str_contains($token, '<')) {
        if (!preg_match('/^[^<>]*<([^<>]+)>$/D', $token, $match)) {
          throw new \InvalidArgumentException('Invalid mailbox brackets.');
        }
        $token = trim($match[1]);
      }
      if (!$validator->isValid($token)) {
        throw new \InvalidArgumentException('Invalid mailbox.');
      }
      $addresses[] = mb_strtolower($token);
    }
    return array_values(array_unique($addresses));
  }

}
