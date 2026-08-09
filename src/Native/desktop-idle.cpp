#include <KIdleTime/KIdleTime>
#include <QDateTime>
#include <QFileInfo>
#include <QGuiApplication>
#include <QJsonDocument>
#include <QJsonObject>
#include <QLockFile>
#include <QSaveFile>

#include <cstdlib>
#include <iostream>
#include <limits>

namespace {

bool writeState(
    const QString &path,
    const QString &state,
    int activeWithinSeconds
)
{
    QSaveFile file(path);
    if (!file.open(QIODevice::WriteOnly)) {
        std::cerr << "Could not write presence state: "
                  << file.errorString().toStdString() << std::endl;
        return false;
    }

    const QJsonObject payload {
        {QStringLiteral("state"), state},
        {QStringLiteral("using_computer"), state == QStringLiteral("active")},
        {QStringLiteral("active_within_seconds"), activeWithinSeconds},
        {QStringLiteral("observed_at_ms"), QDateTime::currentMSecsSinceEpoch()},
        {QStringLiteral("monitor_pid"), QCoreApplication::applicationPid()},
        {QStringLiteral("source"), QStringLiteral("KIdleTime Wayland idle notifications")},
    };
    file.write(QJsonDocument(payload).toJson(QJsonDocument::Compact));
    file.write("\n");
    return file.commit();
}

} // namespace

int main(int argc, char *argv[])
{
    QGuiApplication application(argc, argv);
    if (argc != 4 || QString::fromLocal8Bit(argv[1]) != QStringLiteral("--monitor")) {
        std::cerr << "usage: navi-brain-idle --monitor STATE_PATH ACTIVE_WITHIN_SECONDS" << std::endl;
        return 2;
    }

    char *end = nullptr;
    const long activeWithinSeconds = std::strtol(argv[3], &end, 10);
    if (
        end == argv[3]
        || *end != '\0'
        || activeWithinSeconds < 1
        || activeWithinSeconds > std::numeric_limits<int>::max() / 1000
    ) {
        std::cerr << "ACTIVE_WITHIN_SECONDS must be a positive integer" << std::endl;
        return 2;
    }

    const QString statePath = QString::fromLocal8Bit(argv[2]);
    QLockFile lock(statePath + QStringLiteral(".lock"));
    lock.setStaleLockTime(0);
    if (!lock.tryLock()) {
        return 0;
    }

    const int thresholdSeconds = static_cast<int>(activeWithinSeconds);
    if (!writeState(statePath, QStringLiteral("unknown"), thresholdSeconds)) {
        return 1;
    }

    KIdleTime *idleTime = KIdleTime::instance();
    int timeoutId = -1;
    QObject::connect(
        idleTime,
        &KIdleTime::timeoutReached,
        &application,
        [&](int identifier, int) {
            if (identifier == timeoutId) {
                writeState(statePath, QStringLiteral("away"), thresholdSeconds);
                idleTime->catchNextResumeEvent();
            }
        }
    );
    QObject::connect(
        idleTime,
        &KIdleTime::resumingFromIdle,
        &application,
        [&]() {
            writeState(statePath, QStringLiteral("active"), thresholdSeconds);
            idleTime->stopCatchingResumeEvent();
        }
    );

    timeoutId = idleTime->addIdleTimeout(thresholdSeconds * 1000);
    idleTime->catchNextResumeEvent();
    return application.exec();
}
