<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Meta Information -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" pan-favicon="" href="data:image/svg+xml;charset=UTF-8;base64,PHN2ZyBmaWxsPSJub25lIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGhlaWdodD0iMTAwJSIgdmlld0JveD0iMCAwIDMyIDMyIiB3aWR0aD0iMTAwJSIgZml0PSIiIHByZXNlcnZlQXNwZWN0UmF0aW89InhNaWRZTWlkIG1lZXQiIGZvY3VzYWJsZT0iZmFsc2UiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHBhdGggZD0iTTIgNmg0djEwSDJ6IiBmaWxsPSIjODVBNEU2Ij48L3BhdGg+PHBhdGggZD0iTTIgMTZoNHYxMEgyeiIgZmlsbD0iIzMzNjdENiI+PC9wYXRoPjxwYXRoIGQ9Ik04IDZoNHYxMEg4eiIgZmlsbD0iIzg1QTRFNiI+PC9wYXRoPjxwYXRoIGQ9Ik04IDE2aDR2MTBIOHoiIGZpbGw9IiMzMzY3RDYiPjwvcGF0aD48cGF0aCBkPSJNMTQgNmg0djEwaC00eiIgZmlsbD0iIzg1QTRFNiI+PC9wYXRoPjxwYXRoIGQ9Ik0xNCAxNmg0djEwaC00eiIgZmlsbD0iIzMzNjdENiI+PC9wYXRoPjxwYXRoIGQ9Ik0yMCA2aDR2MTBoLTR6IiBmaWxsPSIjODVBNEU2Ij48L3BhdGg+PHBhdGggZD0iTTIwIDE2aDR2MTBoLTR6IiBmaWxsPSIjMzM2N0Q2Ij48L3BhdGg+PHBhdGggZD0iTTI2IDZoNHYxMGgtNHoiIGZpbGw9IiM4NUE0RTYiPjwvcGF0aD48cGF0aCBkPSJNMjYgMTZoNHYxMGgtNHoiIGZpbGw9IiMzMzY3RDYiPjwvcGF0aD48L3N2Zz4=">

    <title>Cloud Tasks for Laravel</title>

    <!-- Style sheets-->
    <link href="https://fonts.googleapis.com/css?family=Nunito" rel="stylesheet">
    <script src="/vendor/cloud-tasks/{{ $manifest['index.html']['file'] }}" type="module"></script>
    @foreach ($manifest['index.html']['css'] as $css)
        <link rel="stylesheet" href="/vendor/cloud-tasks/{{ $css }}">
    @endforeach
</head>
<body class="bg-gray-100">
<div id="app"></div>

<!-- Global Horizon Object -->
<script>
    window.CloudTasks = @json($cloudTasksScriptVariables);
</script>

</body>
</html>
