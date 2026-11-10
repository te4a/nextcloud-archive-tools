<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Notification;

use OCP\L10N\IFactory;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

final class Notifier implements INotifier {
    public function __construct(private IFactory $languages, private IURLGenerator $urls) {}

    public function getID(): string { return 'archive_tools'; }
    public function getName(): string { return $this->languages->get('archive_tools')->t('Archive Tools'); }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'archive_tools'
            || !in_array($notification->getSubject(), ['extraction.succeeded', 'extraction.failed'], true)) {
            throw new UnknownNotificationException();
        }
        $l = $this->languages->get('archive_tools', $languageCode);
        $parameters = $notification->getSubjectParameters();
        $name = (string)($parameters['sourceName'] ?? '');
        $subject = $notification->getSubject() === 'extraction.succeeded'
            ? $l->t('Archive "%s" was extracted.', [$name])
            : $l->t('Extracting archive "%s" failed. Open the task for details.', [$name]);
        return $notification->setParsedSubject($subject)
            ->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath('archive_tools', 'app.svg')))
            ->setLink($this->urls->linkToRouteAbsolute('archive_tools.page.index', ['jobId' => $notification->getObjectId()]));
    }
}
