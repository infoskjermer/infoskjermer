<?php

declare(strict_types=1);

namespace Drupal\signage_dashboard\Controller;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\signage_player\Service\ScreenPlaybackResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly ScreenPlaybackResolver $screenPlaybackResolver,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('signage_player.screen_playback_resolver'),
    );
  }

  public function build(): array {
    $account = $this->currentUser();
    $screens = $this->loadMyScreens();
    $active_media = $this->collectActiveMedia($screens);

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['signage-dashboard'],
      ],
      '#attached' => [
        'library' => ['signage_dashboard/dashboard'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list', 'paragraph_list'],
      ],

      'intro' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['signage-dashboard__intro'],
        ],
        'welcome' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Velkommen, @name', [
            '@name' => $account->getDisplayName(),
          ]),
        ],
        'lead' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => (string) $this->t('Her ser du skjermene dine og innhold som er aktivt akkurat nå.'),
          '#attributes' => [
            'class' => ['signage-dashboard__lead'],
          ],
        ],
      ],

      'stats' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['signage-dashboard__stats'],
        ],
        'screens_stat' => $this->buildStatCard($this->t('Skjermer'), (string) count($screens)),
        'media_stat' => $this->buildStatCard($this->t('Aktive medier'), (string) count($active_media)),
      ],

      'screens_section' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['dashboard-panel'],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => (string) $this->t('Dine skjermer'),
        ],
        'content' => $this->buildMyScreensSection($screens),
      ],

      'media_section' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['dashboard-panel'],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => (string) $this->t('Aktive medier'),
        ],
        'content' => $this->buildActiveMediaSection($active_media),
      ],
    ];
  }

  /**
   * @return \Drupal\node\NodeInterface[]
   */
  private function loadMyScreens(): array {
    $storage = $this->entityTypeManager()->getStorage('node');

    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'screen')
      ->condition('uid', (int) $this->currentUser()->id())
      ->sort('created', 'DESC')
      ->execute();

    if (!$nids) {
      return [];
    }

    $nodes = $storage->loadMultiple($nids);

    return array_values(array_filter(
      $nodes,
      static fn ($node) => $node instanceof NodeInterface
    ));
  }

  private function collectActiveMedia(array $screens): array {
    $items = [];
    $seen = [];

    foreach ($screens as $screen) {
      $resolved = $this->screenPlaybackResolver->resolve((int) $screen->id());

      foreach ($resolved['items'] as $item) {
        $key = $screen->id() . ':' . $item['slide_id'];

        if (isset($seen[$key])) {
          continue;
        }
        $seen[$key] = TRUE;

        $items[] = [
          'screen_id' => (int) $screen->id(),
          'screen_title' => $screen->label(),
          'slide_id' => (int) $item['slide_id'],
          'slide_title' => (string) $item['title'],
          'body' => !empty($item['body']) ? Unicode::truncate((string) $item['body'], 140, TRUE, TRUE) : '',
          'duration' => (int) $item['duration'],
          'media_url' => $item['media_url'] ?? NULL,
        ];
      }
    }

    usort($items, function (array $a, array $b): int {
      $screen_compare = strcasecmp($a['screen_title'], $b['screen_title']);
      if ($screen_compare !== 0) {
        return $screen_compare;
      }
      return strcasecmp($a['slide_title'], $b['slide_title']);
    });

    return $items;
  }

  private function buildStatCard($label, string $value): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dashboard-stat'],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => (string) $label,
        '#attributes' => [
          'class' => ['dashboard-stat__label'],
        ],
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $value,
        '#attributes' => [
          'class' => ['dashboard-stat__value'],
        ],
      ],
    ];
  }

  private function buildMyScreensSection(array $screens): array {
    if (!$screens) {
      return $this->buildEmptyMessage($this->t('Du har ingen skjermer ennå.'));
    }

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dashboard-screen-list'],
      ],
    ];

    foreach ($screens as $index => $screen) {
      $location = '-';
      if (
        $screen->hasField('field_screen_location') &&
        !$screen->get('field_screen_location')->isEmpty() &&
        $screen->get('field_screen_location')->entity
      ) {
        $location = $screen->get('field_screen_location')->entity->label();
      }

      $playlist = '-';
      if (
        $screen->hasField('field_screen_playlist') &&
        !$screen->get('field_screen_playlist')->isEmpty() &&
        $screen->get('field_screen_playlist')->entity
      ) {
        $playlist = $screen->get('field_screen_playlist')->entity->label();
      }

      $build['item_' . $index] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['dashboard-screen-item'],
        ],

        'main' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['dashboard-screen-item__main'],
          ],
          'title' => [
            '#type' => 'link',
            '#title' => $screen->label(),
            '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $screen->id()]),
            '#options' => [
              'attributes' => [
                'class' => ['dashboard-screen-item__title'],
              ],
            ],
          ],
          'meta' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['dashboard-screen-meta'],
            ],
            'location' => $this->buildMetaItem($this->t('Location'), $location),
            'playlist' => $this->buildMetaItem($this->t('Playlist'), $playlist),
          ],
        ],

        'side' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['dashboard-screen-item__side'],
          ],
          'status' => $this->buildStatusBadge($screen->isPublished()),
          'player' => [
            '#type' => 'link',
            '#title' => $this->t('Open player'),
            '#url' => Url::fromUri('internal:/player/' . $screen->id()),
            '#options' => [
              'attributes' => [
                'class' => ['dashboard-action-link'],
              ],
            ],
          ],
        ],
      ];
    }

    return $build;
  }

  private function buildActiveMediaSection(array $items): array {
    if (!$items) {
      return $this->buildEmptyMessage($this->t('Ingen aktive medier akkurat nå.'));
    }

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dashboard-media-grid'],
      ],
    ];

    foreach ($items as $index => $item) {
      $card = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['dashboard-media-card'],
        ],
        'thumb' => $this->buildMediaThumb($item),
        'content' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['dashboard-media-card__content'],
          ],
          'screen' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $item['screen_title'],
            '#attributes' => [
              'class' => ['dashboard-media-card__screen'],
            ],
          ],
          'title' => [
            '#type' => 'link',
            '#title' => $item['slide_title'],
            '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $item['slide_id']]),
            '#options' => [
              'attributes' => [
                'class' => ['dashboard-media-card__title'],
              ],
            ],
          ],
          'body' => $item['body'] !== '' ? [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $item['body'],
            '#attributes' => [
              'class' => ['dashboard-media-card__body'],
            ],
          ] : [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => (string) $this->t('Ingen beskrivelse.'),
            '#attributes' => [
              'class' => ['dashboard-media-card__body', 'is-muted'],
            ],
          ],
          'meta' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['dashboard-media-card__meta'],
            ],
            'duration' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => (string) $this->t('@seconds s', ['@seconds' => $item['duration']]),
              '#attributes' => [
                'class' => ['dashboard-pill'],
              ],
            ],
          ],
          'actions' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['dashboard-media-card__actions'],
            ],
            'edit' => [
              '#type' => 'link',
              '#title' => $this->t('Edit slide'),
              '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $item['slide_id']]),
              '#options' => [
                'attributes' => [
                  'class' => ['dashboard-action-link'],
                ],
              ],
            ],
            'preview' => !empty($item['media_url']) ? [
              '#type' => 'link',
              '#title' => $this->t('Preview media'),
              '#url' => Url::fromUri($item['media_url']),
              '#options' => [
                'attributes' => [
                  'class' => ['dashboard-action-link'],
                  'target' => '_blank',
                  'rel' => 'noopener',
                ],
              ],
            ] : [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => (string) $this->t('No media'),
              '#attributes' => [
                'class' => ['dashboard-action-link', 'is-muted'],
              ],
            ],
          ],
        ],
      ];

      $build['item_' . $index] = $card;
    }

    return $build;
  }

  private function buildMediaThumb(array $item): array {
    if (!empty($item['media_url'])) {
      return [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['dashboard-media-card__thumb'],
        ],
        'image' => [
          '#type' => 'html_tag',
          '#tag' => 'img',
          '#attributes' => [
            'src' => $item['media_url'],
            'alt' => $item['slide_title'],
            'loading' => 'lazy',
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dashboard-media-card__thumb', 'is-empty'],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => (string) $this->t('No image'),
      ],
    ];
  }

  private function buildMetaItem($label, string $value): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dashboard-meta-item'],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => (string) $label,
        '#attributes' => [
          'class' => ['dashboard-meta-label'],
        ],
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $value,
        '#attributes' => [
          'class' => ['dashboard-meta-value'],
        ],
      ],
    ];
  }

  private function buildStatusBadge(bool $published): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $published ? (string) $this->t('Active') : (string) $this->t('Inactive'),
      '#attributes' => [
        'class' => [
          'dashboard-badge',
          $published ? 'is-active' : 'is-inactive',
        ],
      ],
    ];
  }

  private function buildEmptyMessage($message): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => (string) $message,
      '#attributes' => [
        'class' => ['dashboard-empty'],
      ],
    ];
  }

}