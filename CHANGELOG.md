# Releases
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## 3.6.4 - 2024-01-28

**Added**

- Support path in STACKKIT_CLOUD_TASKS_HANDLER

## 3.6.3 - 2023-12-28

**Changed**

- Test package with PHP 8.3

## 3.6.2 - 2023-10-05

**Fixed**

- A return in `pushRaw` to return the uuid was missing from the current implementation.

## 3.6.1 - 2023-09-01

**Changed**

- Updated PHPSeclib from 2.x to 3.x. Ensure 3.6.1 can be downloaded by adding phpseclib in the update command: `composer update "stackkit/laravel-google-cloud-tasks-queue" "phpseclib/phpseclib"`

## 3.6.0 - 2023-07-02

**Changed**

- Added config `signed_audience` toggle extra security by signing the token audience (thanks @Tarpsvo)

**Fixed**

- Fixed task name sanitization failed when the task was a closure

## 3.5.1 - 2023-05-27

**Changed**

- Bumped PHPSeclib from 2.x to 3.x

**Fixed**

- Fixed #104

## 3.5.0 - 2023-04-09

**Added**

- Added more descriptive job names in Cloud Tasks (thanks @i386 #100)

## 3.4.1 - 2023-02-17

**Fixed**

- Fixed #95

## 3.4.0 - 2023-02-08

**Changed**

- Added support for Laravel 10

## 3.3.2 - 2022-11-19

**Changed**

- Added helpful message if application generates unsafe http handler URL due to potentially TrustedProxies not being configured.

## 3.3.1 - 2022-10-22

**Fixed**

- Fixes [#73](https://github.com/stackkit/laravel-google-cloud-tasks-queue/issues/73) Cannot access protected property Illuminate\Queue\Jobs\SyncJob::$job

## 3.3.0 - 2022-10-15

**Added**

- Jobs can now be released back onto the queue.

## 3.2.1 - 2022-09-02

**Fixed**

- Jobs were dispatched before a DB commit if `after_commit` or `afterCommit()` was used. This has now been corrected.

**Added**

- Request validation for the TaskHandler endpoint.

## 3.2.0 - 2022-08-13

**Added**

- Added support for jobs that use the `Illuminate\Contracts\Queue\ShouldBeEncrypted` contract

## 3.1.4 - 2022-06-24

**Fixed**

- Fixed usage of incorrect header to set count retries ([#55](https://github.com/stackkit/laravel-google-cloud-tasks-queue/discussions/55))
- Fix getRetryUntilTimestamp not working due to incomplete task name ([#56](https://github.com/stackkit/laravel-google-cloud-tasks-queue/discussions/56))

## 3.1.3 - 2022-06-19

**Fixed**

- Fixed JWT 5.x/6.x constraint problems

## 3.1.2 - 2022-04-24

**Fixed**

- Fixed JWT decode error caused by update in google/auth

## 3.1.1 - 2022-04-11

**Fixed**

- Fix 'audience does not match'

## 3.1.0 - 2022-04-09

**Added**

- Added support for `dispatchDeadline`. See README how to configure.

## 3.0.0 - 2022-04-03

**Added**

- Added support for PostgreSQL
- Added a dashboard used to monitor jobs

**Removed**

- Dropped support for PHP 7.2 and 7.3
- Dropped support for Laravel 5.x

## 2.3.0 - 2022-02-09

**Changed**

- Added Laravel 9 support.

## 2.2.1 - 2022-01-08

**Changed**

- Bumped dependencies.

## 2.2.0 - 2021-12-18

**Fixed**

- Setting maxAttempts in Cloud Tasks to -1 now sets unlimited attempts. Previously it would only attempt once.
- When a job fails (maxAttempts reached or retryUntil/Max retry duration passed) it is now deleted.

**Added**

- Added support for 'Max retry duration'

## 2.1.3 - 2021-08-21

**Fixed**

- Fix cache expiration condition [#19](https://github.com/stackkit/laravel-google-cloud-tasks-queue/discussions/29#discussioncomment-1205080)

## 2.1.2 - 2021-06-05

**Fixed**

- Fixed connection names other than [cloudtasks] not working

## 2.1.1 - 2021-05-14

**Fixed**

- Added support for Laravel Octane and fix [#17](https://github.com/stackkit/laravel-google-cloud-tasks-queue/issues/17)

## 2.1.0 - 2021-05-11

**Added**

- Handling of failed jobs

## 2.1.0-beta1 - 2021-03-28

**Added**

- Handling of failed jobs

## 2.0.1 - 2020-12-06

**Fixed**

- Fixed certificates cached too long ([#3](https://github.com/stackkit/laravel-google-cloud-tasks-queue/issues/3))

## 2.0.0 - 2020-10-11

**Added**

- Support for Laravel 8

**Changed**

- Change authentication method from config value path to Application Default Standard

## 1.0.0 - 2020-06-20

**Added**

- Public release of the package.

## 1.0.0-alpha1 - 2020-06-17

**Added**

- Initial release of the package.
