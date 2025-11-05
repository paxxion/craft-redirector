let gulp = require("gulp");
let minify = require("gulp-minify");
let postcss = require('gulp-postcss');
let autoprefixer = require('autoprefixer');
let cssnano = require('cssnano');
let tailwindPostcss = require('@tailwindcss/postcss');
let esbuild = require('gulp-esbuild');

/* css */
let css = {
    from: './src/css/*.css',
    to: './dist/css',
};

function gulpCSS() {
    return (
        gulp
            .src(css.from)
            .pipe(postcss([
                tailwindPostcss(),
                autoprefixer(),
                // cssnano(),
            ]))
            .pipe(gulp.dest(css.to))
    );
}

/* js */
let js = {
    from: './src/js/**/*.js',
    to:  './dist/js'
};

function gulpJS() {
    gulpCSS();

    return (
        gulp
            .src(js.from)
            .pipe(
                esbuild({
                    bundle: true,
                    platform: 'browser',
                    target: ['es2018'],
                    format: 'iife',
                }),
                minify({
                    ext:{
                        debug: '.debug.js',
                        min:'.js'
                    },
                    noSource: true
                })
            )
            .pipe(gulp.dest(js.to))
    );
}

function watch() {
    gulp.watch(
        [ css.from, "../templates/**/*.twig" ],
        { ignoreInitial: false },
        gulpCSS
    );

    /* js */
    gulp.watch(
        [ js.from ],
        { ignoreInitial: false },
        gulpJS
    );
};

exports.default = watch;
