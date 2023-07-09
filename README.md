# Bluesky Holiday Progress Bot
A [novelty bot](https://bsky.app/profile/holidayprogress.bsky.social) in PHP to post the "progress" toward (and announcement of) the next holiday/event on a Google calendar.

The original idea was inspired by the "Year Progress" bot and developed for [Twitter](https://twitter.com/holidayprogress). With Twitter's API changes in 2023, the [original source](https://github.com/zaskem/twitterbot-holidayprogress) was archived. In July, 2023 the bot was ported to Bluesky.

Data is sourced from Google calendar (public or shared) via the Google Calendar API, analyzed/calculated, and pushed to Bluesky via the ATProto API with a basic PHP cURL POST.

## Requirements
To run the bot code, the following libraries/accounts/things are required:

* [Google APIs Client Library for PHP](https://github.com/googleapis/google-api-php-client) must be installed/available on the bot host;
* A project with service account and key pair from the [Google API Console](https://console.developers.google.com);
* An event source Google calendar such as "[Holidays in United States](https://calendar.google.com/calendar/embed?src=en.usa%23holiday%40group.v.calendar.google.com&ctz=America%2FChicago)";
* A bot/user account on Bluesky (and an App Password) for posts;
* A host on which to run this code (not at a browsable path).

Unlike the Twitter bot, due to how Bluesky (the ATProto API) works there is no complex authentication or posting mechanism. This code should run on any host with PHP and its cURL extension enabled.

### Google API
Creating a project, creating a service account and key for the Google API falls outside the scope of this README. At a minimum, a project must be available on the [Google API Console](https://console.developers.google.com) and a Service Account with an automatically-generated key pair created. As the bot is a consumer of the Google API, no special permissions are required for the service account. You will need the key name/ID and associated `.json` file.

### ATProto (Bluesky) API
For local development/testing, it is possible to grab your own clone of the [ATProto](https://github.com/bluesky-social/atproto) source and run it completely independently/offline. It's a good way to learn some basics (and help you get things working before going live), but this is not necessary. All you need to post to Bluesky is a username and app password.

## Bot Configuration
Four configuration files should exist in the `config/` directory. Example/Stubout versions are provided, and each one of these files should be copied without the `.example` extension (e.g. `cp bot.php.example bot.php`):

* `bot.php`
* `google.php`
* `posts.php`
* `status.php` (optional: file is autocreated and autoupdated)

Edit each file as necessary for your bot (`status.php` can be ignored). Note that `bot.php` and `google.php` require the most cusomization as they contain the Bluesky host and credentials, source calendar ID, key/secret and other path/data source information.

## Bot Usage, Crontab Setup
The entire process can be kicked off with a simple command:
`php PostProgress.php`

Out of the box, this command will return a JSON string with debug information to verify the bot was successful (or not). Once satisfied/ready for production, setting `$debug_bot = false;` in `config/bot.php` will disable this output.

Cron can/should be used for production. A simple default crontab setting might look like this:
```bash
*/15 * * * * /path/to/php /path/to/PostProgress.php
```
The above will run the bot script every 15 minutes, which would adequately post almost every single percentage increment over the course of a day. Set this to whatever makes sense for the source calendar event cadence.

It is possible to run this on a Windows machine (assuming PHP is installed with the cURL extension enabled) via Scheduled Task. The same principle applies (run `php.exe` and give it the `/path/to/PostProgress.php` argument).

## Posting
By design, `PostProgress.php` will _not_ attempt to post if the progress percentage hasn't changed (and some other scenarios). The debug information in `skipped*` values will identify reasons for a skipped post.

`PostProgress.php` should require no direct modification or attention. It is a self-contained basic mechanism to run all the core bot bits. It's not fancy, but it works in about 300 neatly-formatted lines.

## Troubleshooting
This bot doesn't have a lot of moving parts, so there's not a lot to troubleshoot. There are two likely problems to troubleshoot:

* Failure to get calendar/source data; and
* Failure to post.

Setting `$debug_bot` to `true` in `config/bot.php` will output a substantial amount of information about the process. If event details are not populating, there is likely a Google API or calendar/pointer issue. If a `"postResponse":"Bad Request"` status shows up, there is likely a Twitter API/POST problem. Both problems are likely related to credentials/keys/secrets.

Setting `$debug_post` to `true` in `config/posts.php` will output specific information from the POST action.

`$debug_post` can be used independently of or in addition to `$debug_bot`.

## Contributors
Originally developed as a novelty bot/project by [@zaskem](https://github.com/zaskem) to play with the Twitter API. Project remained in operation on Twitter for a couple of years before being shelved (due to Twitter's API changes). Project was ported over to Bluesky as a good "first go" at using the ATProto API.

Free to fork and use/modify as your own! PR's are welcome if applicable to the core project (bugfixes, reasonable enhancements). The original intent was to be lightweight, relatively simple, and easy to dissect -- not necessarily a feature-complete project.