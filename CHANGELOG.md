# Releases
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

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
